<?php
/**
 * Magento
 *
 * @author    WakaMage http://www.wakamage.com <cs@wakamage.com>
 * @copyright Copyright (C) 2013 WakaMage. (http://www.wakamage.com)
 *
 */
 
class Sprint_Migs_Model_Bri extends Mage_Payment_Model_Method_Abstract {
	protected $_code = 'bri';
	
	protected $_isInitializeNeeded      = true;
	protected $_canUseInternal          = true;
	protected $_canUseForMultishipping  = true;
	
	protected $_formBlockType = 'migs/payment_form_bri';
	
	public function getOrderPlaceRedirectUrl() {
		
		$allowedCurrencies = Mage::getModel('directory/currency')->getConfigAllowCurrencies(); 
		
		if (in_array('IDR', $allowedCurrencies)){
			return Mage::getUrl('migs/payment/sending', array('_secure' => true));
		}
		if (!in_array('IDR', $allowedCurrencies)){
			return Mage::getUrl('migs/payment/noidr');
		}

	}
	
	/*public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setBcaTenor($data->getBcaTenor());
        return $this;
    }*/
	
}
