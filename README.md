# Openpay-Magento2-CheckoutLending

Módulo para pagos con Checkout Lending con Openpay para Magento2 (soporte hasta v2.3.0)

## Instalación

1. Ir a la carpeta raíz del proyecto de Magento y seguir los siguiente pasos:

**Para versiones de Magento < 2.3.0**
```bash    
composer require openpay/magento2-checkoutlending:~3.0.0
```

**Para versiones de Magento >= 2.3.0**
```bash    
composer require openpay/magento2-checkoutlending:~3.4.0
```

**Para versiones de Magento >= 2.3.5**
```bash    
composer require openpay/magento2-checkoutlending:~4.0.*
```

2. Después se procede a habilitar el módulo,actualizar y limpiar cache de la plataforma.

```bash    
php bin/magento module:enable Openpay_CheckoutLending --clear-static-content
php bin/magento setup:upgrade
php bin/magento cache:clean
```

3. Para configurar el módulo desde el panel de administración de la tienda diríjase a: Stores > Configuration > Sales > Payment Methods
