# Openpay-Magento2-CheckoutLending

Módulo para pagos con checkout lending (Compra ahora paga después) con Openpay para Magento2 (soporte hasta v2.4.*)

## Instalación

1. Ir a la carpeta raíz del proyecto de Magento y seguir los siguiente pasos:

```bash    
composer require openpay/magento2-checkout-lending:1.1.*   
php bin/magento module:enable Openpay_CheckoutLending --clear-static-content
php bin/magento setup:upgrade
php bin/magento cache:clean
```
2. Para configurar el módulo desde el panel de administración de la tienda diríjase a: Stores > Configuration > Sales > Payment Methods

## Actualización
En caso de ya contar con el módulo instalado y sea necesario actualizar, seguir los siguientes pasos:

```bash
composer clear-cache
composer update openpay/magento2-checkout-lending
bin/magento setup:upgrade
php bin/magento cache:clean
```
