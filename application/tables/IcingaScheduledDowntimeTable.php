<?php

namespace Icinga\Module\Director\Tables;

use Icinga\Module\Director\Web\Table\QuickTable;

class IcingaScheduledDowntimeTable extends QuickTable
{
    protected $searchColumns = array(
        'downtime',
    );

    public function getColumns()
    {
        return array(
            'id'           => 't.id',
            'downtime'     => 't.object_name',
            'display_name' => 't.display_name',
        );
    }

    protected function getActionUrl($row)
    {
        return $this->url('director/downtime', array('name' => $row->downtime));
    }

    public function getTitles()
    {
        $view = $this->view();
        return array(
            'downtime'     => $view->translate('Downtime'),
            'display_name' => $view->translate('Display Name'),
        );
    }

    public function getBaseQuery()
    {
        $db = $this->connection()->getConnection();
        $query = $db->select()->from(
            array('t' => 'icinga_scheduled_downtime'),
            array()
        );

        return $query;
    }
}
