# WooCommerce Tracking Receiver

The **WooCommerce Tracking Receiver** plugin allows you to receive order data and provide tracking service on your WooCommerce store. It lets you display tracking information for orders directly in your WordPress admin panel and on your site using a shortcode.

## Features

- Receive order tracking data through webhooks.
- Display tracking information on your WordPress admin panel.
- Show tracking information on any page using a simple shortcode.
- Easy setup and configuration.

## Installation

### 1. Install the Plugin
- Download and install the **WooCommerce Tracking Receiver** plugin on your WordPress site.
- Navigate to the **Plugins** section in WordPress.
- Click **Add New** and upload the plugin zip file.
- After installation, click **Activate**.

### 2. Get Webhook URL and API Key
- Navigate to **WP Admin > Order Tracking > Settings**.
- Here you will find the **Webhook URL** and **API Key**. These will be used to receive order tracking data from your external system.

### 3. Display Tracking Information Using Shortcode
- To display tracking information on a page, simply add the following shortcode to any page or post:
  
```php
[woo_tracking_receiver_display]
```

- This will render all the tracking details on the page where the shortcode is placed.

## How to Use

1. **Receive Order Tracking Information:**
 - Once the plugin is installed, it will start receiving tracking data from your external service via webhooks.
 - This information can be viewed in your WordPress Admin Panel.

2. **View Tracking Details in Admin:**
 - To view all the tracking information, navigate to **WP Admin > Order Tracking**. Here, you’ll see a list of orders with associated tracking data.

3. **Use Shortcode for Frontend Display:**
 - The shortcode `[woo_tracking_receiver_display]` can be added to any page to display tracking information for the relevant orders.

## Configuration

### Webhook URL and API Key
- The **Webhook URL** and **API Key** are used to integrate the tracking data source with your WooCommerce store. You can find these details under **WP Admin > Order Tracking > Settings**.

Ensure that your external service is sending tracking data to the Webhook URL provided.

### Settings Page
- The settings page allows you to configure the plugin’s behavior. You can access it by navigating to **WP Admin > Order Tracking > Settings**.

## FAQs

### How do I find my API Key and Webhook URL?
- Navigate to **WP Admin > Order Tracking > Settings** to find the **Webhook URL** and **API Key**.

### How do I display tracking info on my site?
- Simply use the shortcode `[woo_tracking_receiver_display]` on any page or post where you want to display the tracking information.

### Can I customize the tracking display?
- The plugin currently does not support custom styling. However, you can add custom CSS to your WordPress theme to modify the display of the tracking information.

---

*This plugin is developed by Chetan Vaghela.*

