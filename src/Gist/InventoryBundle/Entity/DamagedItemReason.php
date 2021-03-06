<?php

namespace Gist\InventoryBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

use Gist\CoreBundle\Template\Entity\HasGeneratedID;
use Gist\InventoryBundle\Template\Entity\HasProduct;
use Gist\InventoryBundle\Template\Entity\HasInventoryAccount;
use Doctrine\Common\Collections\ArrayCollection;
use Gist\CoreBundle\Template\Entity\TrackCreate;

/**
 * @ORM\Entity
 * @ORM\Table(name="inv_damaged_item_reasons")
 */
class DamagedItemReason
{
    use HasGeneratedID;

    /**
     * @ORM\Column(type="string")
     */
    protected $reason;

    public function __construct()
    {
        $this->initHasGeneratedID();
    }

    public function setReason($reason)
    {
        $this->reason = $reason;
        return $this;
    }

    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return \stdClass
     */
    public function toData()
    {
        $data = new \stdClass();
        $this->dataHasGeneratedID($data);
        return $data;
    }
}

