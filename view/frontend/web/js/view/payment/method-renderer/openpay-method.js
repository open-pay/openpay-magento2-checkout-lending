/**
 * Openpay_CheckoutLending Magento JS component
 *
 * @category    Openpay
 * @package     Openpay_CheckoutLending
 * @author      Federico Balderas
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */
/*browser:true*/
/*global define*/
define(
    [
        'Magento_Checkout/js/view/payment/default'
    ],
    function (Component) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Openpay_CheckoutLending/payment/openpay-checkout'
            }
        });
    }
);