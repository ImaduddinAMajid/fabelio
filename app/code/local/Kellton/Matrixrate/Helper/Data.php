<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   Mage
 * @package    Mage_Shipping
 * @copyright  Copyright (c) 2008 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 /**
  * Kellton Shipping Module
  *
  * NOTICE OF LICENSE
  *
  * This source file is subject to the Open Software License (OSL 3.0)
  * that is bundled with this package in the file LICENSE.txt.
  * It is also available through the world-wide-web at this URL:
  * http://opensource.org/licenses/osl-3.0.php
  * If you did not receive a copy of the license and are unable to
  * obtain it through the world-wide-web, please send an email
  * to license@magentocommerce.com so we can send you a copy immediately.
  *
  * DISCLAIMER
  *
  * Do not edit or add to this file if you wish to upgrade Magento to newer
  * versions in the future. If you wish to customize Magento for your
  * needs please refer to http://www.magentocommerce.com for more information.
  *
  * Shipping MatrixRates
  *
  *   
*/

/**
 * Shipping data helper
 */
class Kellton_Matrixrate_Helper_Data extends Mage_Core_Helper_Abstract
{
    
    public function get_shipping_method($sku){
        $return_array = array();
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('matrixrate_shipping/matrixrate');
        $select = "SELECT  * from {$table} where sku = '{$sku}'";
        $row = $read->fetchAll($select);
        $time = time();
        $cutt_off_time = Mage::getStoreConfig('carriers/matrixrate/cut_off_time');
        
        if(count($row) > 0){
            foreach($row as $key=>$val){
                if($val['express_fee_enabled']){
                    $delivery_date = $this->cutt_off_time_calculation($val['express_number_of_days']);
                    $return_array[$key]['delivery_date']=$delivery_date;
                    $return_array[$key]['price'] = $val['price'];
                    $return_array[$key]['pk'] = $val['pk'];
                }else{
                    $delivery_date = $this->cutt_off_time_calculation($val['standard_number_of_days']);
                    $return_array[$key]['delivery_date']=$delivery_date;
                    $return_array[$key]['price'] = "Free";
                    $return_array[$key]['pk'] = $val['pk'];
                }
            }
        }else{
            $delivery_date = $this->cutt_off_time_calculation(1);
            $return_array['0']['delivery_date'] = $delivery_date;
        }
        return $return_array;
    }
    
    public function cutt_off_time_calculation($delivery_days=''){
        $config_cutt_off_time = Mage::getStoreConfig('carriers/matrixrate/cut_off_time');
        $current_local_time = strtotime(strftime('%X'));
       
        $cut_off_time = strtotime(strftime($config_cutt_off_time));
      
        if($delivery_days!='' && $delivery_days == 0  && $current_local_time < $cut_off_time){
         
             $delivery_date = date('j F');
             
        }elseif($delivery_days!='' && $delivery_days == 0  && $current_local_time > $cut_off_time){
          
            $delivery_date = date('j F',strtotime(strftime('%X').'+ 1 day'));
            
        }elseif($delivery_days!='' && $delivery_days == 1 && $current_local_time < $cut_off_time){
        
            $delivery_date = date('j F',strtotime(strftime('%X').'+ 1 day'));
        }elseif($delivery_days!='' && $delivery_days == 1  && $current_local_time > $cut_off_time)
            {
         
            $delivery_date = date('j F',strtotime(strftime('%X').'+ 2 day'));
        }elseif($delivery_days=='' && $current_local_time < $cut_off_time ){
            
             $delivery_date = date('j F',strtotime(strftime('%X').'+ 1 day'));
        }elseif($delivery_days=='' && $current_local_time > $cut_off_time ){
         
             $delivery_date = date('j F',strtotime(strftime('%X').'+ 2 day'));
        }else{
            $delivery_date = date('j F',strtotime(strftime('%X').'+ 1 day'));
        }
        return $delivery_date;
    }
    
    public function get_delivery_type($id=''){
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('matrixrate_shipping/matrixrate');
        if($id!=''){
        $select = "SELECT  * from {$table} where pk = '{$id}'";
        $row = $read->fetchAll($select);
        if(count($row) > 0){
            $return_array = array();
            foreach($row as $key=>$val){
               if($val['express_fee_enabled']){
                    $delivery_date = $this->cutt_off_time_calculation($val['express_number_of_days']);
                    $return_array[$key]['delivery_date']=$delivery_date;
                    $return_array[$key]['delivery_type'] = $val['delivery_type'];
                   
                }else{
                    $delivery_date = $this->cutt_off_time_calculation($val['standard_number_of_days']);
                    $return_array[$key]['delivery_date']=$delivery_date;
                    $return_array[$key]['delivery_type'] = $val['delivery_type'];
                }
            }
        }else{
             $delivery_date = $this->cutt_off_time_calculation();
             $return_array['0']['delivery_date'] = $delivery_date;
             $return_array['0']['delivery_type'] = "Standard";
        }
        }else{
            $delivery_date = $this->cutt_off_time_calculation();
            $return_array['0']['delivery_date'] = $delivery_date;
            $return_array['0']['delivery_type'] = "Standard";
        }
        return $return_array;
    }
    
    
    public function give_option_array($id){
        
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('matrixrate_shipping/matrixrate');
        $select = "SELECT  * from {$table} where pk = '{$id}'";
        $row = $read->fetchAll($select);
        return $row;
    }
    

    
    public function get_sku_data($sku){
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('matrixrate_shipping/matrixrate');
        $select = "SELECT  * from {$table} where sku = '{$sku}'";
        $row = $read->fetchAll($select);
        return $row;
    }
    
    public function set_delivery_time($product_id, $days){
        $_product= Mage::getModel('catalog/product')->load($product_id);
        $arg_value="";
        if($days<=5){
            $arg_value="3-5 Hari";
            
        }else if($days>5 && $days<=10){
            $arg_value="Di bawah 2 Minggu";
        }else if($days>10 && $days<=15){
            $arg_value="Di bawah 3 Minggu";
        }else{
            $arg_value="4-6 Minggu";
        }
        $arg_attribute="deliverytime";
        $attRibuteId=0;
        $attribute = Mage::getModel('eav/config')->getAttribute('catalog_product', 'deliverytime');
        $flag=0;

        foreach ( $attribute->getSource()->getAllOptions(true, true) as $option )
        {

            if($arg_value == $option['label'])
            {

                unset($attribute);
                $flag=1;
                $attRibuteId = $option['value'] ; 
            }
        }
        if($flag==0){
            $attribute_model        = Mage::getModel('eav/entity_attribute');
            $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

            $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
            $attribute              = $attribute_model->load($attribute_code);

            $attribute_table        = $attribute_options_model->setAttribute($attribute);
            $options                = $attribute_options_model->getAllOptions(false);

            $value['option'] = array($arg_value,$arg_value);
            $result = array('value' => $value);
            $attribute->setData('option',$result);
            $attribute->save();

            $attribute = Mage::getModel('eav/config')->getAttribute('catalog_product', $arg_attribute);
            foreach ( $attribute->getSource()->getAllOptions(true, true) as $option )
            {
                if($arg_value == $option['label'])
                {
                    unset($attribute);
                    $attRibuteId = $option['value'] ; 
                }
            }

        }
        $_product->setDeliverytime($attRibuteId);
        $_product->save();
       /* if($days<=5){
            $_product->setDeliverytime("3-5 Hari");
            
        }else if($days>5 && $days<=10){
            $_product->setDeliverytime("Di bawah 2 Minggu");
        }else if($days>10 && $days<=15){
            $_product->setDeliverytime("Di bawah 3 Minggu");
        }else{
            $_product->setDeliverytime("4-6 Minggu");
        }/**/
        
       // $_product->setDeliverytimeBackorder($days." Days");
        
    }

}
