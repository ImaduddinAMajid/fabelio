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
 * @category    Mage
 * @package     Mage_Checkout
 * @copyright   Copyright (c) 2013 Magento Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Shopping cart controller
 */
require 'Mage/Checkout/controllers/CartController.php';
class Fabmod_Checkout_CartController extends Mage_Core_Controller_Front_Action
{
    /**
     * Action list where need check enabled cookie
     *
     * @var array
     */
    protected $_cookieCheckActions = array('add');

    /**
     * Retrieve shopping cart model object
     *
     * @return Mage_Checkout_Model_Cart
     */
    protected function _getCart()
    {
        return Mage::getSingleton('checkout/cart');
    }

    /**
     * Get checkout session model instance
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get current active quote instance
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote()
    {
        return $this->_getCart()->getQuote();
    }

    /**
     * Set back redirect url to response
     *
     * @return Mage_Checkout_CartController
     * @throws Mage_Exception
     */
    protected function _goBack()
    {
        $returnUrl = $this->getRequest()->getParam('return_url');
        if ($returnUrl) {

            if (!$this->_isUrlInternal($returnUrl)) {
                throw new Mage_Exception('External urls redirect to "' . $returnUrl . '" denied!');
            }

            $this->_getSession()->getMessages(true);
            $this->getResponse()->setRedirect($returnUrl);
        } elseif (!Mage::getStoreConfig('checkout/cart/redirect_to_cart')
            && !$this->getRequest()->getParam('in_cart')
            && $backUrl = $this->_getRefererUrl()
        ) {
            $this->getResponse()->setRedirect($backUrl);
        } else {
            if (($this->getRequest()->getActionName() == 'add') && !$this->getRequest()->getParam('in_cart')) {
                $this->_getSession()->setContinueShoppingUrl($this->_getRefererUrl());
            }
            $this->_redirect('checkout/cart');
        }
        return $this;
    }

    /**
     * Initialize product instance from request data
     *
     * @return Mage_Catalog_Model_Product || false
     */
    protected function _initProduct()
    {
        $productId = (int) $this->getRequest()->getParam('product');
        if ($productId) {
            $product = Mage::getModel('catalog/product')
                ->setStoreId(Mage::app()->getStore()->getId())
                ->load($productId);
            if ($product->getId()) {
                return $product;
            }
        }
        return false;
    }

    /**
     * Shopping cart display action
     */
    public function indexAction()
    {
        $cart = $this->_getCart();
         Mage::getSingleton('core/session')->unsShippingAmount();
         Mage::getSingleton('core/session')->unsShippingDescription();
        if ($cart->getQuote()->getItemsCount()) {
            $cart->init();
            $cart->save();

            if (!$this->_getQuote()->validateMinimumAmount()) {
                $minimumAmount = Mage::app()->getLocale()->currency(Mage::app()->getStore()->getCurrentCurrencyCode())
                    ->toCurrency(Mage::getStoreConfig('sales/minimum_order/amount'));

                $warning = Mage::getStoreConfig('sales/minimum_order/description')
                    ? Mage::getStoreConfig('sales/minimum_order/description')
                    : Mage::helper('checkout')->__('Minimum order amount is %s', $minimumAmount);

                $cart->getCheckoutSession()->addNotice($warning);
            }
        }

        // Compose array of messages to add
        $messages = array();
        foreach ($cart->getQuote()->getMessages() as $message) {
            if ($message) {
                // Escape HTML entities in quote message to prevent XSS
                $message->setCode(Mage::helper('core')->escapeHtml($message->getCode()));
                $messages[] = $message;
            }
        }
        $cart->getCheckoutSession()->addUniqueMessages($messages);

        /**
         * if customer enteres shopping cart we should mark quote
         * as modified bc he can has checkout page in another window.
         */
        $this->_getSession()->setCartWasUpdated(true);

        Varien_Profiler::start(__METHOD__ . 'cart_display');
        $this
            ->loadLayout()
            ->_initLayoutMessages('checkout/session')
            ->_initLayoutMessages('catalog/session')
            ->getLayout()->getBlock('head')->setTitle($this->__('Shopping Cart'));
        $this->renderLayout();
        Varien_Profiler::stop(__METHOD__ . 'cart_display');
    }

    /**
     * Add product to shopping cart action
     *
     * @return Mage_Core_Controller_Varien_Action
     * @throws Exception
     */
    public function addAction()
    {
        if (!$this->_validateFormKey()) {
            $this->_goBack();
            return;
        }
        $cart   = $this->_getCart();
        $params = $this->getRequest()->getParams();
        try {
            if (isset($params['qty'])) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                $params['qty'] = $filter->filter($params['qty']);
            }

            $product = $this->_initProduct();
            $related = $this->getRequest()->getParam('related_product');

            /**
             * Check product availability
             */
            if (!$product) {
                $this->_goBack();
                return;
            }

            $cart->addProduct($product, $params);
            if (!empty($related)) {
                $cart->addProductsByIds(explode(',', $related));
            }

            $cart->save();

            $this->_getSession()->setCartWasUpdated(true);

            /**
             * @todo remove wishlist observer processAddToCart
             */
            Mage::dispatchEvent('checkout_cart_add_product_complete',
                array('product' => $product, 'request' => $this->getRequest(), 'response' => $this->getResponse())
            );

            if (!$this->_getSession()->getNoCartRedirect(true)) {
                if (!$cart->getQuote()->getHasError()) {
                    $message = $this->__('%s was added to your shopping cart.', Mage::helper('core')->escapeHtml($product->getName()));
                    $this->_getSession()->addSuccess($message);
                }
                $this->_goBack();
            }
        } catch (Mage_Core_Exception $e) {
            if ($this->_getSession()->getUseNotice(true)) {
                $this->_getSession()->addNotice(Mage::helper('core')->escapeHtml($e->getMessage()));
            } else {
                $messages = array_unique(explode("\n", $e->getMessage()));
                foreach ($messages as $message) {
                    $this->_getSession()->addError(Mage::helper('core')->escapeHtml($message));
                }
            }

            $url = $this->_getSession()->getRedirectUrl(true);
            if ($url) {
                $this->getResponse()->setRedirect($url);
            } else {
                $this->_redirectReferer(Mage::helper('checkout/cart')->getCartUrl());
            }
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('Cannot add the item to shopping cart.'));
            Mage::logException($e);
            $this->_goBack();
        }
    }

    /**
     * Add products in group to shopping cart action
     */
    public function addgroupAction()
    {
        $orderItemIds = $this->getRequest()->getParam('order_items', array());

        if (!is_array($orderItemIds) || !$this->_validateFormKey()) {
            $this->_goBack();
            return;
        }

        $itemsCollection = Mage::getModel('sales/order_item')
            ->getCollection()
            ->addIdFilter($orderItemIds)
            ->load();
        /* @var $itemsCollection Mage_Sales_Model_Mysql4_Order_Item_Collection */
        $cart = $this->_getCart();
        foreach ($itemsCollection as $item) {
            try {
                $cart->addOrderItem($item, 1);
            } catch (Mage_Core_Exception $e) {
                if ($this->_getSession()->getUseNotice(true)) {
                    $this->_getSession()->addNotice($e->getMessage());
                } else {
                    $this->_getSession()->addError($e->getMessage());
                }
            } catch (Exception $e) {
                $this->_getSession()->addException($e, $this->__('Cannot add the item to shopping cart.'));
                Mage::logException($e);
                $this->_goBack();
            }
        }
        $cart->save();
        $this->_getSession()->setCartWasUpdated(true);
        $this->_goBack();
    }

    /**
     * Action to reconfigure cart item
     */
    public function configureAction()
    {
        // Extract item and product to configure
        $id = (int) $this->getRequest()->getParam('id');
        $quoteItem = null;
        $cart = $this->_getCart();
        if ($id) {
            $quoteItem = $cart->getQuote()->getItemById($id);
        }

        if (!$quoteItem) {
            $this->_getSession()->addError($this->__('Quote item is not found.'));
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            $params = new Varien_Object();
            $params->setCategoryId(false);
            $params->setConfigureMode(true);
            $params->setBuyRequest($quoteItem->getBuyRequest());

            Mage::helper('catalog/product_view')->prepareAndRender($quoteItem->getProduct()->getId(), $this, $params);
        } catch (Exception $e) {
            $this->_getSession()->addError($this->__('Cannot configure product.'));
            Mage::logException($e);
            $this->_goBack();
            return;
        }
    }

    /**
     * Update product configuration for a cart item
     */
    public function updateItemOptionsAction()
    {
        $cart   = $this->_getCart();
        $id = (int) $this->getRequest()->getParam('id');
        $params = $this->getRequest()->getParams();

        if (!isset($params['options'])) {
            $params['options'] = array();
        }
        try {
            if (isset($params['qty'])) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                $params['qty'] = $filter->filter($params['qty']);
            }

            $quoteItem = $cart->getQuote()->getItemById($id);
            if (!$quoteItem) {
                Mage::throwException($this->__('Quote item is not found.'));
            }

            $item = $cart->updateItem($id, new Varien_Object($params));
            if (is_string($item)) {
                Mage::throwException($item);
            }
            if ($item->getHasError()) {
                Mage::throwException($item->getMessage());
            }

            $related = $this->getRequest()->getParam('related_product');
            if (!empty($related)) {
                $cart->addProductsByIds(explode(',', $related));
            }

            $cart->save();

            $this->_getSession()->setCartWasUpdated(true);

            Mage::dispatchEvent('checkout_cart_update_item_complete',
                array('item' => $item, 'request' => $this->getRequest(), 'response' => $this->getResponse())
            );
            if (!$this->_getSession()->getNoCartRedirect(true)) {
                if (!$cart->getQuote()->getHasError()) {
                    $message = $this->__('%s was updated in your shopping cart.', Mage::helper('core')->escapeHtml($item->getProduct()->getName()));
                    $this->_getSession()->addSuccess($message);
                }
                $this->_goBack();
            }
        } catch (Mage_Core_Exception $e) {
            if ($this->_getSession()->getUseNotice(true)) {
                $this->_getSession()->addNotice($e->getMessage());
            } else {
                $messages = array_unique(explode("\n", $e->getMessage()));
                foreach ($messages as $message) {
                    $this->_getSession()->addError($message);
                }
            }

            $url = $this->_getSession()->getRedirectUrl(true);
            if ($url) {
                $this->getResponse()->setRedirect($url);
            } else {
                $this->_redirectReferer(Mage::helper('checkout/cart')->getCartUrl());
            }
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('Cannot update the item.'));
            Mage::logException($e);
            $this->_goBack();
        }
        $this->_redirect('*/*');
    }

    /**
     * Update shopping cart data action
     */
    public function updatePostAction()
    {
        if (!$this->_validateFormKey()) {
            $this->_redirect('*/*/');
            return;
        }

        $updateAction = (string)$this->getRequest()->getParam('update_cart_action');

        switch ($updateAction) {
            case 'empty_cart':
                $this->_emptyShoppingCart();
                break;
            case 'update_qty':
                $this->_updateShoppingCart();
                break;
            default:
                $this->_updateShoppingCart();
        }

        $this->_goBack();
    }

    /**
     * Update customer's shopping cart
     */
    protected function _updateShoppingCart()
    {
        try {
            $cartData = $this->getRequest()->getParam('cart');
            if (is_array($cartData)) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                foreach ($cartData as $index => $data) {
                    if (isset($data['qty'])) {
                        $cartData[$index]['qty'] = $filter->filter(trim($data['qty']));
                    }
                }
                $cart = $this->_getCart();
                if (! $cart->getCustomerSession()->getCustomer()->getId() && $cart->getQuote()->getCustomerId()) {
                    $cart->getQuote()->setCustomerId(null);
                }

                $cartData = $cart->suggestItemsQty($cartData);
                $cart->updateItems($cartData)
                    ->save();
            }
            $this->_getSession()->setCartWasUpdated(true);
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError(Mage::helper('core')->escapeHtml($e->getMessage()));
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('Cannot update shopping cart.'));
            Mage::logException($e);
        }
    }

    /**
     * Empty customer's shopping cart
     */
    protected function _emptyShoppingCart()
    {
        try {
            $this->_getCart()->truncate()->save();
            $this->_getSession()->setCartWasUpdated(true);
        } catch (Mage_Core_Exception $exception) {
            $this->_getSession()->addError($exception->getMessage());
        } catch (Exception $exception) {
            $this->_getSession()->addException($exception, $this->__('Cannot update shopping cart.'));
        }
    }

    /**
     * Delete shoping cart item action
     */
    public function deleteAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            try {
                $this->_getCart()->removeItem($id)
                  ->save();
            } catch (Exception $e) {
                $this->_getSession()->addError($this->__('Cannot remove the item.'));
                Mage::logException($e);
            }
        }
        $this->_redirectReferer(Mage::getUrl('*/*'));
    }
    
    public function deleteAjaxAction()
    {
        
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            try {
                $this->_getCart()->removeItem($id)
                  ->save();
                $result['error'] = false;
                $result['success'] = true;
                $result['html'] = $this->getUpdatedCartHtml();
                $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();
                $grandtotal = round($totals["grand_total"]->getValue());
                $_coreHelper = Mage::helper('core');
                $result['grand_total']=$_coreHelper->formatPrice($grandtotal, false)." (".Mage::helper('checkout/cart')->getItemsCount()." Barang)";
               
            } catch (Exception $e) {
                $result['error'] = true;
                $result['success'] = false;
                $result['error_message'] = $this->__('Cannot remove the item.');
                //$this->_getSession()->addError($this->__('Cannot remove the item.'));
                //Mage::logException($e);
            }
        }
        //echo "<pre>"; print_r($result); echo "</pre>";
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
        //$this->_redirectReferer(Mage::getUrl('*/*'));
    }
    
    public function getUpdatedCartHtml(){
        $_coreHelper = Mage::helper('core');
        $matrixrate_helper = Mage::helper('matrixrate');
        $items = Mage::getSingleton('checkout/cart')->getQuote()->getAllItems();
        $shipping_amount_array = Mage::getSingleton('core/session')->getShippingAmount();
        
        $html = "";
       // $html .= '<div class="accordion-inner-content" >';
        $html .= '<form name="review_form" id="review_form" action="javascript://" method="POST">';
        $html .= '<div class="checkout-header-table">';
        $html .= '<div class="checkout-item">
                      <div class="cell"></div>
                      <div class="cell">Item</div>
                      <div class="cell">Harga Satuan</div>
                      <div class="cell">Jumlah</div>
                      <div class="cell">Tanggal Pengiriman </div>
                      <div class="cell">Subtotal</div>
                      <div class="cell"></div>
                    </div>';
        # Loop data comes here
                    $quote_items_array = array();
                foreach($items as $key=>$item){
                    
                    $quote_items_array[]= $item->getID();
                    $product_id = $item->getProductID();
                    $item_id = $item->getID();
                    $product = Mage::getModel('catalog/product')->load($product_id);
                    $product_name = $item->getName();
                    $product_price = $item->getBaseRowTotal();
                    $product_qty = $item->getQty();
                    $product_sku = $item->getSku();
                    $manufacturer = $product->getAttributeText('manufacturer');
                    $product_image = Mage::helper('catalog/image')->init($product, 'thumbnail')->resize(150);
                    
                    $html .= '<div class="checkout-cart-item">
                             <div class="cart-cell cell5">';
                    $html .= '<img src="'.$product_image.'" width="155" height="155" />';
                    $html .= '</div>
                             <div class="cart-cell cell1">';
                    $html .= '<h4>'.$product_name.'</h4>';
                    $html .= '<label>'.$manufacturer.'</label>';
                    $html .= '</div>';
                    $html .= '<div class="cart-cell cell2"><span>'.$_coreHelper->formatPrice($product_price, false).'</span></div>';
                    $html .= '<div class="cart-cell cell4"><input disabled="disabled" type="number" value="'.$product_qty.'" /></div>';
                    $html .= '<div class="cart-cell cell">';
                    $delivery_array = $matrixrate_helper->get_shipping_method($product_sku);
                    //echo "Product ID : ".$product_id."\n";
                    //echo "<pre>"; print_r($shipping_amount_array); echo "</pre>";
                    //exit;
                    if(count($delivery_array) > 1){
                        $html .= '<div class="checkout-quantity">';
                        foreach($delivery_array as $key=>$val): 
                                    if($val['price']!="Free"){
                                        $radio_name = "express-".$product_id;
                                    }else{
                                        $radio_name = "standard-".$product_id;
                                    }
                                    $shipping_code = "matrixrate_matrixrate_".$val['pk'];
                                    if($val['Standard']==0){
                                        $checked = "checked='checked'";
                                    }
                                    if($shipping_amount_array[$product_id] > 0 && $val['price']!="Free"){
                                        $checked = "checked='checked'";
                                    }else{
                                        $checked = "";
                                    }
                                    if($val['price']!="Free"): 
                                        $ship_price =  $_coreHelper->formatPrice($val['price'], false); 
                                    else: 
                                        $ship_price = $val['price'];
                                    endif;
                                    $shippingCodePrice[] = "'".$shipping_code."':".(float)$val['price'];
                                    $html .= '<input type="radio" '.$checked.' name="'.$product_id.'" id="'.$radio_name.'" rel="shipping_method" value="'.$val['pk'].'"/><label for="'.$radio_name.'">'.$val['delivery_date'].'- '.$ship_price.' </label>';
                        endforeach;
                        $html .= '</div>';
                    }else{
                        $html .= '<div class="checkout-quantity">';
                        foreach($delivery_array as $key=>$val):
                            $radio_name_free = "free-std-".$product_id;
                            $html .= '<input disabled="disabled" type="radio" checked="checked" name="'.$product_id.'" value="0" rel="shipping_method" id="'.$radio_name_free.'"/><label for="'.$radio_name_free.'">'.$val['delivery_date'].' - Free';
                        endforeach;
                        $html .= '</div>';
                    }
                        $html .= ' </div>
                              <div class="cart-cell cell2">
                          ';
                        $total_price = $product_qty * $product_price;
                        $html .= '<span>'.$_coreHelper->formatPrice($total_price, false).'</span>';
                        $html .= '</span>
                              </div>
                      <div class="cart-cell cell">';
                        $html .= '</span>
                              </div>
                      <div class="cart-cell cell"><img class="delete-item" rel="'.$item_id.'" src="'.Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN).'frontend/smartwave/porto/images/icon-close-black.svg" width="20" style="cursor:pointer;"  data-toggle="modal" data-target="#deleteitem" />';
                    $html .= '</div></div>';
                    
                    
                    if(array_key_exists($product_id,$shipping_amount_array)){
                        // echo "<pre>"; print_r($shipping_amount_array); echo "</pre>";
                         $shipping_amount = array_sum($shipping_amount_array);
                     }else{
                         $shipping_amount = 0;
                         Mage::getSingleton('core/session')->unsShippingAmount();
                         Mage::getSingleton('core/session')->unsShippingDescription();
                         Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress()->setShippingAmount($shipping_amount);
                        Mage::getSingleton('checkout/session')->getQuote()->setShippingAmount($shipping_amount);
                        // Mage::getSingleton('core/session')->unsShippingAmount();
                     }
                }
        
         //echo "<pre>"; print_r($shipping_amount_array); echo "</pre>";
        $html .= '</div>';
        $html .= '<div class="checkout-total-main">';
        $html .= '<div class="checkout-total-left">
                    <label>Apakah Anda memiliki voucher Fabelio? <span onclick="remove_me()">Klik disini</span></label>
                    <div class="form-group  has-feedback" id="coupon_div" style="display:none;">
                    <input type="text" class="form-control" name="coupon_code" id="coupon_code" onkeypress="apply_coupon()"/>
                    <i class="fa fa-check-circle form-control-feedback" style="display:none;"></i>
                  </div>


<!--                  <div class="form-group has-error has-feedback">
                  <input type="text" class="form-control" >
                  <i class="fa fa-close form-control-feedback" ></i>
                </div>-->

                  </div>';
        $html .= '<div class="checkout-total-right">
                      <div class="checkout-total-inner">
                        <label>Jumlah Belanjaan Anda</label>';
        
        $html .= '<span>'.$_coreHelper->formatPrice(Mage::helper('checkout/cart')->getQuote()->getSubtotal(),false).'</span>';
        $html .= '</div>';
        $html .= '<div class="checkout-total-inner">
                        <label>Ongkos Kirim</label>';
         
       
        // exit;
         
         
         
         $html .= '<span>'.$_coreHelper->formatPrice($shipping_amount,false).'</span>';
         $html .= '</div>';
         $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();
         if(isset($totals["discount"])){
                $coupon_discount_amount = $totals["discount"]->getValue();
         }
         $html .= '<div class="checkout-total-inner checkout-discount">
                        <label>Diskon Voucher</label>
                        <span>'.$_coreHelper->formatPrice($coupon_discount_amount, false).'</span>
                      </div>

                      <div class="checkout-total-inner g-total">
                        <label>Grand Total</label>';
         
                $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals(); //Total object
                $grandtotal = $totals["grand_total"]->getValue(); //Grandtotal value 
                $formattedPrice = $_coreHelper->formatPrice($grandtotal , false);
                $html .= '<span>'.$formattedPrice.'</span>';
                $html .= '</div></div>';
                $html .= ' </div>
                </form>
                  
              </div>';
               // exit;
        return $html;
    }

    /**
     * Initialize shipping information
     */
    public function estimatePostAction()
    {
        $country    = (string) $this->getRequest()->getParam('country_id');
        $postcode   = (string) $this->getRequest()->getParam('estimate_postcode');
        $city       = (string) $this->getRequest()->getParam('estimate_city');
        $regionId   = (string) $this->getRequest()->getParam('region_id');
        $region     = (string) $this->getRequest()->getParam('region');

        $this->_getQuote()->getShippingAddress()
            ->setCountryId($country)
            ->setCity($city)
            ->setPostcode($postcode)
            ->setRegionId($regionId)
            ->setRegion($region)
            ->setCollectShippingRates(true);
        $this->_getQuote()->save();
        $this->_goBack();
    }

    /**
     * Estimate update action
     *
     * @return null
     */
    public function estimateUpdatePostAction()
    {
        $code = (string) $this->getRequest()->getParam('estimate_method');
        if (!empty($code)) {
            $this->_getQuote()->getShippingAddress()->setShippingMethod($code)/*->collectTotals()*/->save();
        }
        $this->_goBack();
    }

    /**
     * Initialize coupon
     */
    public function couponPostAction()
    {
        /**
         * No reason continue with empty shopping cart
         */
        if (!$this->_getCart()->getQuote()->getItemsCount()) {
            $this->_goBack();
            return;
        }

        $couponCode = (string) $this->getRequest()->getParam('coupon_code');
        if ($this->getRequest()->getParam('remove') == 1) {
            $couponCode = '';
        }
        $oldCouponCode = $this->_getQuote()->getCouponCode();

        if (!strlen($couponCode) && !strlen($oldCouponCode)) {
            $this->_goBack();
            return;
        }

        try {
            $codeLength = strlen($couponCode);
            $isCodeLengthValid = $codeLength && $codeLength <= Mage_Checkout_Helper_Cart::COUPON_CODE_MAX_LENGTH;

            $this->_getQuote()->getShippingAddress()->setCollectShippingRates(true);
            $this->_getQuote()->setCouponCode($isCodeLengthValid ? $couponCode : '')
                ->collectTotals()
                ->save();

            if ($codeLength) {
                if ($isCodeLengthValid && $couponCode == $this->_getQuote()->getCouponCode()) {
                    $this->_getSession()->addSuccess(
                        $this->__('Coupon code "%s" was applied.', Mage::helper('core')->escapeHtml($couponCode))
                    );
                } else {
                    $this->_getSession()->addError(
                        $this->__('Coupon code "%s" is not valid.', Mage::helper('core')->escapeHtml($couponCode))
                    );
                }
            } else {
                $this->_getSession()->addSuccess($this->__('Coupon code was canceled.'));
            }

        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (Exception $e) {
            $this->_getSession()->addError($this->__('Cannot apply the coupon code.'));
            Mage::logException($e);
        }

        $this->_goBack();
    }
    
    
    
    
    public function couponPostAjaxAction()
    {
         $_coreHelper = Mage::helper('core');
        $matrixrate_helper = Mage::helper('matrixrate');
        /**
         * No reason continue with empty shopping cart
         */
        if (!$this->_getCart()->getQuote()->getItemsCount()) {
            
            $result['error'] = true;
            $result['success'] = false;
        }

        $couponCode = (string) $this->getRequest()->getParam('coupon_code');
        if ($this->getRequest()->getParam('remove') == 1) {
            $couponCode = '';
        }
        $oldCouponCode = $this->_getQuote()->getCouponCode();

        if (!strlen($couponCode) && !strlen($oldCouponCode)) {
            $result['error'] = true;
            $result['success'] = false;
        }

        try {
            $codeLength = strlen($couponCode);
            $isCodeLengthValid = $codeLength && $codeLength <= Mage_Checkout_Helper_Cart::COUPON_CODE_MAX_LENGTH;

            $this->_getQuote()->getShippingAddress()->setCollectShippingRates(true);
            $this->_getQuote()->setCouponCode($isCodeLengthValid ? $couponCode : '')
                ->collectTotals()
                ->save();

            if ($codeLength) {
                if ($isCodeLengthValid && $couponCode == $this->_getQuote()->getCouponCode()) {
//                    $this->_getSession()->addSuccess(
//                        $this->__('Coupon code "%s" was applied.', Mage::helper('core')->escapeHtml($couponCode))
//                    );
                    $result['error'] = false;
                    $result['success'] = true;
                    $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();
                $grandtotal = round($totals["grand_total"]->getValue());
                $result['grand_total']=$_coreHelper->formatPrice($grandtotal, false)." (".Mage::helper('checkout/cart')->getItemsCount()." Barang)";
                $quote = Mage::getSingleton('checkout/session')->getQuote();

//                foreach ($quote->getAllItems() as $item){
//
//                     $coupon_discount_amount =  $coupon_discount_amount + $item->getDiscountAmount();
//
//                } 
                
                $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();
                $coupon_discount_amount = $totals["discount"]->getValue();
                
            $shipping_amount_array = Mage::getSingleton('core/session')->getShippingAmount(); 
            $shipping_amount = array_sum($shipping_amount_array);
            Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress()->setShippingAmount($shipping_amount);
            Mage::getSingleton('checkout/session')->getQuote()->setShippingAmount($shipping_amount);
            $totals = Mage::getSingleton('checkout/session')->getQuote()->getTotals();
            Mage::getSingleton('checkout/session')->getQuote()->save();
            $subtotal = round($totals["subtotal"]->getValue());
            $grandtotal = round($totals["grand_total"]->getValue());
            $grandtotal_final = 0;
            $grandtotal_final = $grandtotal + $shipping_amount;
            Mage::getSingleton('checkout/session')->getQuote()->setGrandTotal($grandtotal);
            $return_html .= '
                  <div class="checkout-total-left">
                    <label>Apakah Anda memiliki voucher Fabelio? <span>Klik disini</span></label>
                    <div class="form-group  has-feedback has-success" id="coupon_div">
                    <input type="text" class="form-control" name="coupon_code" id="coupon_code" value="'.$this->__(Mage::helper('core')->escapeHtml($couponCode)).'"/>
                    <i class="fa fa-check-circle form-control-feedback"></i>
                  </div>

<!--                  <div class="form-group has-error has-feedback">
                  <input type="text" class="form-control" >
                  <i class="fa fa-close form-control-feedback" ></i>
                </div>-->

                  </div>
                  <div class="checkout-total-right">
                      <div class="checkout-total-inner">
                        <label>Jumlah Belanjaan Anda</label>';
            
            $return_html .= '<span>'.$_coreHelper->formatPrice(Mage::helper('checkout/cart')->getQuote()->getSubtotal(),false).'</span>';
            $return_html .= ' </div><div class="checkout-total-inner">
                        <label>Ongkos Kirim</label>';
                        
                       // $shipping_amount_array = Mage::getSingleton('core/session')->getShippingAmount();
                       // $shipping_amount = array_sum($shipping_amount_array);
            $return_html .= '<span>'.$_coreHelper->formatPrice($shipping_amount,false).'</span>';
            $return_html .= '</div>';
            if($shipping_amount > 0){
//            $return_html .='<div class="checkout-total-inner checkout-discount" >
//                        <label>Shipping & Handling - Express</label>
//                        <span>'.$_coreHelper->formatPrice($shipping_amount, false).'</span>
//                      </div>';
            }
            $return_html .= '<div class="checkout-total-inner checkout-discount">
                        <label>Diskon Voucher</label>
                        <span>'.$_coreHelper->formatPrice($coupon_discount_amount, false).'</span>
                      </div>';
            $return_html .='<div class="checkout-total-inner g-total">
                        <label>Grand Total</label>';
            $return_html .= '<span>'.$_coreHelper->formatPrice($grandtotal_final, false).'</span>';     
                $result['html'] = $return_html;
                } else {
                    $this->_getSession()->addError(
                        $this->__('Coupon code "%s" is not valid.', Mage::helper('core')->escapeHtml($couponCode))
                    );
                    $result['error'] = true;
                    $result['success'] = false;
                }
            } else {
                $this->_getSession()->addSuccess($this->__('Coupon code was canceled.'));
            }

        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $result['error'] = true;
                    $result['success'] = false;
        } catch (Exception $e) {
            $this->_getSession()->addError($this->__('Cannot apply the coupon code.'));
            Mage::logException($e);
            $result['error'] = true;
                    $result['success'] = false;
        }
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }
    
    
}
