<?php
class Fareye_Qcdata_Model_Resource_Postdata_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract {
  protected function _construct() {
    parent::_construct();
    $this->_init('qcdata/postdata');
  }
}
