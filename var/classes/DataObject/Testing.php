<?php 

/** 
* Inheritance: no
* Variants: no


Fields Summary: 
*/ 

namespace Pimcore\Model\DataObject;

use Pimcore\Model\DataObject\Exception\InheritanceParentNotFoundException;
use Pimcore\Model\DataObject\PreGetValueHookInterface;

/**
*/

class Testing extends Concrete {

protected $o_classId = "1";
protected $o_className = "testing";


/**
* @param array $values
* @return \Pimcore\Model\DataObject\Testing
*/
public static function create($values = array()) {
	$object = new static();
	$object->setValues($values);
	return $object;
}

}

