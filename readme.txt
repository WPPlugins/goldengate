=== GoldenGate ===
Contributors: xirzec
Tags: Picasa, Google, GData
Requires at least: 2.6.0
Tested up to: 2.6.0
Stable Tag: 1.5

GoldenGate integrates WordPress with Picasa Web Albums.

== Description ==

GoldenGate is a WordPress plugin that integrates Picasa Web Albums into WordPress to make it easy to upload photos to Picasa and then feature them on your blog.

PHP5 is required as a dependency of the Zend Framework. PHP extensions that are required by this plugin: ctype, dom, libxml, spl, standard

To see the latest changes, [view the changelog](http://code.google.com/p/goldengate/wiki/ChangeLog)


== Installation ==

1. Upload the `_zend_gdata` directory to your `wp-content/plugins` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Upload the `goldengate` directory to your `wp-content/plugins` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Follow the prompts to link your blog to a Picasa Web Albums account
1. Compose a new post
1. Click on the 'Add image' icon (after 'Add media') and check out the new options!


== Frequently Asked Questions ==

= How do I associate my blog with a different Picasa Web Albums account? =

There is a GoldenGate Options menu underneath Options in the admin panel. You can view and clear the current authentication token there.

= Do I need to install the Zend GData plugin if I already have the Zend Framework? =

No. If your web host is already including the Zend Framework or the Zend GData classes into your PHP installation, you can ignore steps 1 and 2 of the installation.

= What requirements are there? =

PHP5 is required. Your server will also have to accept file uploads if you want to upload through the plugin. PHP extensions that are required by this plugin: ctype, dom, libxml, spl, standard. Versions higher than 1.1 require WordPress 2.5 or higher.

= I need help! =

If you're having trouble with GoldenGate, you should [file an issue](http://code.google.com/p/goldengate/issues/entry) in the [issue tracker](http://code.google.com/p/goldengate/issues/list).

== Screenshots ==

1. The new menus offered in the Write Post page.
