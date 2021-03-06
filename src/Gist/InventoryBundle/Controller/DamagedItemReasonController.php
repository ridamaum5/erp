<?php

namespace Gist\InventoryBundle\Controller;

use Gist\TemplateBundle\Model\CrudController;
use Gist\InventoryBundle\Entity\DamagedItemReason;
use Gist\ValidationException;

class DamagedItemReasonController extends CrudController
{
    public function __construct()
    {
        $this->route_prefix = 'gist_inv_damaged_item_reason';
        $this->title = 'Damaged Item Reasons';

        $this->list_title = 'Damaged Item Reason';
        $this->list_type = 'dynamic';
    }

    protected function newBaseClass()
    {
        return new DamagedItemReason();
    }

    protected function getObjectLabel($obj)
    {
        return $obj->getName();
    }

    protected function getGridColumns()
    {
        $grid = $this->get('gist_grid');

        return array(
            $grid->newColumn('Reason', 'getReason', 'reason'),
        );
    }

    protected function update($o, $data, $is_new = false)
    {
        $o->setReason($data['reason']);
    }
}
