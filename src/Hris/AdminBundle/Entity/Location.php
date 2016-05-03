<?php

namespace Hris\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

use Catalyst\CoreBundle\Template\Entity\HasGeneratedID;
use Catalyst\CoreBundle\Template\Entity\HasName;
use Catalyst\CoreBundle\Template\Entity\TrackCreate;
use Catalyst\ContactBundle\Template\Entity\HasAddress;

use stdClass;

/**
 * @ORM\Entity
 * @ORM\Table(name="hr_location")
 */
class Location
{
    use HasGeneratedID;
    use HasName;
    use TrackCreate;
    use HasAddress;

    public function __construct()
    {
        $this->initTrackCreate();
        $this->initHasAddress();
    }

    public function toData()
    {
        $data = new \stdClass();
        $this->dataHasGeneratedID($data);
        $this->dataTrackCreate($data);
        $this->dataHasName($data);
        $this->dataHasAddress($data);

        return $data;
    }
}
