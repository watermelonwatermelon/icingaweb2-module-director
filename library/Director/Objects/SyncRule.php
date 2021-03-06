<?php

namespace Icinga\Module\Director\Objects;

use Icinga\Application\Benchmark;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Director\Data\Db\DbObject;
use Icinga\Module\Director\Import\PurgeStrategy\PurgeStrategy;
use Icinga\Module\Director\Import\Sync;
use Exception;

class SyncRule extends DbObject
{
    protected $table = 'sync_rule';

    protected $keyName = 'id';

    protected $autoincKeyName = 'id';

    protected $defaultProperties = array(
        'id'                 => null,
        'rule_name'          => null,
        'object_type'        => null,
        'update_policy'      => null,
        'purge_existing'     => null,
        'filter_expression'  => null,
        'sync_state'         => 'unknown',
        'last_error_message' => null,
        'last_attempt'       => null,
    );

    private $sync;

    private $purgeStrategy;

    private $currentSyncRunId;

    private $filter;

    private $hasCombinedKey;

    private $syncProperties;

    private $sourceKeyPattern;

    private $destinationKeyPattern;

    public function listInvolvedSourceIds()
    {
        if (! $this->hasBeenLoadedFromDb()) {
            return array();
        }

        $db = $this->getDb();
        return array_map('intval', array_unique(
            $db->fetchCol(
                $db->select()
                   ->from(array('p' => 'sync_property'), 'p.source_id')
                   ->join(array('s' => 'import_source'), 's.id = p.source_id', array())
                   ->where('rule_id = ?', $this->id)
                   ->order('s.source_name')
            )
        ));
    }

    public function fetchInvolvedImportSources()
    {
        $sources = array();

        foreach ($this->listInvolvedSourceIds() as $sourceId) {
            $sources[$sourceId] = ImportSource::load($sourceId, $this->getConnection());
        }

        return $sources;
    }

    public function getLastSyncTimestamp()
    {
        if (! $this->hasBeenLoadedFromDb()) {
            return null;
        }

        $db = $this->getDb();
        $query = $db->select()->from(
            array('sr' => 'sync_run'),
            'sr.start_time'
        )->where('sr.rule_id = ?', $this->id)
        ->order('sr.start_time DESC')
        ->limit(1);

        return $db->fetchOne($query);
    }

    public function getLastSyncRunId()
    {
        if (! $this->hasBeenLoadedFromDb()) {
            return null;
        }

        $db = $this->getDb();
        $query = $db->select()->from(
            array('sr' => 'sync_run'),
            'sr.id'
        )->where('sr.rule_id = ?', $this->id)
        ->order('sr.start_time DESC')
        ->limit(1);

        return $db->fetchOne($query);
    }

    public function getPriorityForNextProperty()
    {
        if (! $this->hasBeenLoadedFromDb()) {
            return 1;
        }
        
        $db = $this->getDb();
        return $db->fetchOne(
            $db->select()
                ->from(
                    array('p' => 'sync_property'),
                    array('priority' => '(CASE WHEN MAX(p.priority) IS NULL THEN 1 ELSE MAX(p.priority) + 1 END)')
                )->where('p.rule_id = ?', $this->id)
        );
    }

    public function matches($row)
    {
        if ($this->filter_expression === null) {
            return true;
        }

        return $this->filter()->matches($row);
    }

    public function checkForChanges($apply = false)
    {
        $hadChanges = false;

        Benchmark::measure('Checking sync rule ' . $this->rule_name);
        try {
            $this->last_attempt = date('Y-m-d H:i:s');
            $this->sync_state = 'unknown';
            $sync = $this->sync();
            if ($sync->hasModifications()) {
                Benchmark::measure('Got modifications for sync rule ' . $this->rule_name);
                $this->sync_state = 'pending-changes';
                if ($apply && $runId = $sync->apply()) {
                    Benchmark::measure('Successfully synced rule ' . $this->rule_name);
                    $this->sync_state = 'in-sync';
                    $this->currentSyncRunId = $runId;
                }

                $hadChanges = true;

            } else {
                Benchmark::measure('No modifications for sync rule ' . $this->rule_name);
                $this->sync_state = 'in-sync';
            }

            $this->last_error_message = null;
        } catch (Exception $e) {
            $this->sync_state = 'failing';
            $this->last_error_message = $e->getMessage();
            // TODO: Store last error details / trace?
        }

        if ($this->hasBeenModified()) {
            $this->store();
        }

        return $hadChanges;
    }

    public function getExpectedModifications()
    {
        return $this->sync()->getExpectedModifications();
    }

    public function applyChanges()
    {
        return $this->checkForChanges(true);
    }

    public function getCurrentSyncRunId()
    {
        return $this->currentSyncRunId;
    }

    public function getSourceKeyPattern()
    {
        if ($this->hasCombinedKey()) {
            return $this->sourceKeyPattern;
        } else {
            return null; // ??
        }
    }

    public function getDestinationKeyPattern()
    {
        if ($this->hasCombinedKey()) {
            return $this->destinationKeyPattern;
        } else {
            return null; // ??
        }
    }

    protected function sync()
    {
        if ($this->sync === null) {
            $this->sync = new Sync($this);
        }

        return $this->sync;
    }

    protected function filter()
    {
        if ($this->filter === null) {
            $this->filter = Filter::fromQueryString($this->filter_expression);
        }

        return $this->filter;
    }

    public function purgeStrategy()
    {
        if ($this->purgeStrategy === null) {
            $this->purgeStrategy = $this->loadConfiguredPurgeStrategy();
        }

        return $this->purgeStrategy;
    }

    // TODO: Allow for more
    protected function loadConfiguredPurgeStrategy()
    {
        if ($this->purge_existing === 'y') {
            return PurgeStrategy::load('ImportRunBased', $this);
        } else {
            return PurgeStrategy::load('PurgeNothing', $this);
        }
    }

    /**
     * Whether we have a combined key (e.g. services on hosts)
     *
     * @return bool
     */
    public function hasCombinedKey()
    {
        if ($this->hasCombinedKey === null) {

            $this->hasCombinedKey = false;

            // TODO: Move to Objects
            if ($this->object_type === 'service') {
                $hasHost = false;
                $hasObjectName = false;

                foreach ($this->getSyncProperties() as $key => $property) {
                    if ($property->destination_field === 'host') {
                        $hasHost = $property->source_expression;
                    }
                    if ($property->destination_field === 'object_name') {
                        $hasObjectName = $property->source_expression;
                    }
                }

                if ($hasHost !== false && $hasObjectName !== false) {
                    $this->hasCombinedKey = true;
                    $this->sourceKeyPattern = sprintf(
                        '%s!%s',
                        $hasHost,
                        $hasObjectName
                    );

                    $this->destinationKeyPattern = '${host}!${object_name}';
                }
            } elseif ($this->object_type === 'datalistEntry') {
                $hasList = false;
                $hasName = false;

                foreach ($this->getSyncProperties() as $key => $property) {
                    if ($property->destination_field === 'list_id') {
                        $hasList = $property->source_expression;
                    }
                    if ($property->destination_field === 'entry_name') {
                        $hasName = $property->source_expression;
                    }
                }

                if ($hasList !== false && $hasName !== false) {
                    $this->hasCombinedKey = true;
                    $this->sourceKeyPattern = sprintf(
                        '%s!%s',
                        $hasList,
                        $hasName
                    );

                    $this->destinationKeyPattern = '${list_id}!${entry_name}';
                }

            }
        }

        return $this->hasCombinedKey;
    }

    public function getSyncProperties()
    {
        if (! $this->hasBeenLoadedFromDb()) {
            return array();
        }

        if ($this->syncProperties === null) {
            $this->syncProperties = $this->fetchSyncProperties();
        }

        return $this->syncProperties;
    }

    public function fetchSyncProperties()
    {
        $db = $this->getDb();
        return SyncProperty::loadAll(
            $this->getConnection(),
            $db->select()
               ->from('sync_property')
               ->where('rule_id = ?', $this->id)
               ->order('priority DESC')
        );

        return $this->syncProperties;
    }
}
