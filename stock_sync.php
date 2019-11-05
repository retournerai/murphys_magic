<?php
/*
Plugin Name: Anthony's Murphy's Magic Solution
Author: Ron Hessing and David O'Reilly

*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

################################################################################
# PLUGIN INSTALLATION functions

#create post for last-sync time stamp (only required at plugin install)
function create_sync_post()
{
  if(!isset($_POST['DO NOT DELETE - last successful sync date'])){
  // Create post object
    $my_post = array(
      'post_title'    => 'DO NOT DELETE - last successful sync date',
      'post_content'  => 'DO NOT DELETE -- this post is used by the sync plugin to record 
      the last_successful_sync_date',
      'post_status'   => 'private'
      );
      $post_id = wp_insert_post( $my_post );
      #print_r('Post created successfully!!!!!!!!!!!!!!!!');
  } else{
    #print_r('post already exists.');
  }
}

function start_murphys_magic_sync(){
// do here what needs to be done automatically as per schedule
  //run_sync(); ( this action is added below. )

}

function activate_wp_cron() 
{
  if(!wp_next_scheduled('start_murphys_magic_sync'))
  {
    print_r("start_murphys_magic_sync is now being scheduled");
    wp_schedule_event(time(),'hourly','start_murphys_magic_sync');
  }
}

function deactivate_wp_cron(){
  //find out when the last event was scheduled
  $timestamp=wp_next_scheduled('start_murphys_magic_sync');
  //unschedule previous event if any
  wp_unschedule_event($timestamp,'start_murphys_magic_sync');
}

// // Activates function if plugin is activated
register_activation_hook( __FILE__, 'create_sync_post');

//cron job activation
// makes sure function is called whenever WordPress loads
add_action('wp_loaded','activate_wp_cron');

# hooks sync function into cron job.
add_action('start_murphys_magic_sync', 'run_sync');
#add_action('init', 'start_murphys_magic_sync'); # for debugging only

// // dectivates function if plugin is deactivated
register_deactivation_hook(__FILE__,'deactivate_wp_cron');

################################################################################
# GENERAL functions

# Returns the post object in an array
function retrieve_post($post_title)
{
  $post = get_page_by_title( $post_title, 'OBJECT', 'post' );
  #print_r($post);
  sleep(1); # do not remove
  return $post;
}

function update_post_content($new_content, $post_id)
{
  #print_r("<p> New content: ".$new_content). "<\p>";
  $my_post = array(
      'ID'        => $post_id,
      'post_content' => $new_content,
      'post_type' => 'post',
  );
  
  # if post fails then show error message for debugging
  $post_id = wp_update_post( $my_post, true );
  if (is_wp_error($post_id)) {
	   $errors = $post_id->get_error_messages();
	   foreach ($errors as $error) {
       echo $error;
     }
   }
}

#function to get stock quantities from MurphysMagic.
function update_stock_woocommerce(string $start_date, string $end_date)
{
  $successfully_synced = false;
  set_time_limit(60000); // set wordpress timeout period to 100 min
  
  # GET INFO FROM MURPHYSMAGIC
//////////////////////////////////////////////////////////////////////////

  $wsdl = get_option('MurphysMagic1_web_services_wsdl');
  $username = get_option('MurphysMagic1_web_services_username');
  $password = get_option('MurphysMagic1_web_services_password');
  $namespace = get_option('MurphysMagic1_web_services_namespace');

  date_default_timezone_set('UTC');
  
  
  $client = new SoapClient($wsdl,array('connection_timeout' => 60000));

  $auth = array(
          'Username'=> $username,
          'Password'=> $password
          );

  $header = new SoapHeader($namespace,'SoapAuthenticationHeader',$auth,false);

  $client->__setSoapHeaders(array($header));
////////////////////////////////////////////////////////////////////////

  $day1 = strtotime($start_date);
  $day2 = strtotime($end_date);
  
  //Test dates
  //$day1 = strtotime("2019-10-04 21:40:26");
  //$day2 = strtotime("2019-10-14 21:40:26");
  
  $gotonextpage = true;
  $pagenumber = 0;

  while ($gotonextpage == true){
    $pagenumber++;
    #print_r($pagenumber);

    try
    {
      $parameters = array('startDate' => $day1, 'endDate' => $day2, 'page' => $pagenumber);
      $result = $client->GetAvailabileUpdates($parameters);
    }

    catch (Exception $e)
    {
        printf("Soap Fault: %s\n", $e->Reason);
    }

    # If there are items on the page, then add to database.
    #print_r(" Number of sync updates on page: ");
    #print_r(count((array)$result->items));
    if (count((array)$result->items) > 0) {

      ### EDIT ITEMS IN LOCAL DATABASE ###
      for ($i=0; $i<count($result->items->QuantityUpdate); $i++)
      {
        $query = new WC_Product_Query(array(
            'sku' => $result->items->QuantityUpdate[$i]->InternalID,
            'return' => 'ids',
        ));
        $products = $query->get_products();
       # print_r(" SKU: ");
       # print_r($result->items->QuantityUpdate[$i]->InternalID);

        #If product is in our local database, update stock quantity.
        if (count($products)>0){
          $product = $products[0];
          if(!check_if_whitelisted($product))
          {
          	$stock_quantity = $result->items->QuantityUpdate[$i]->QuantityAvailable;
          	wc_update_product_stock($product, $stock_quantity, 'set');
          } else {
          	#skip this product. (local stock numbers are being recorded for this product
          	# and it should not be synced to external stock numbers)
          	
          }
        }

      }

    } else {
    $gotonextpage = false;
    $successfully_synced = true;
    }
  }
  set_time_limit(60); // set wordpress timeout period back to 1 min
  return $successfully_synced;
}
################################################################################

function cleanup_whitelist() #removes products whose local stock has dropped to zero
{
  #print_r("cleaning up whitelist ...");
  $args = array(
    'post_type' => 'product',
    'meta_query' => array(
      'relation' => 'AND',
      array(
        'key' => '_is_local_stock',
        'value' => 'yes',
      ),
      array(
        'key' => '_stock',
        'value' => '0'
      )
    )
  );
  $products_local_stock_now_zero = get_posts( $args );
  #make an array of all items to be deleted from whitelist (and to be synced with murphy's magic again)

  foreach ( $products_local_stock_now_zero as $product ){
    $ID = ($product->ID);
    #print_r($ID);
    #$SKU = ????? may be useful here to sync product immediately with Murphys magic
    delete_post_meta($ID, '_is_local_stock');
  }
}

##################################################################################
function check_if_whitelisted($id)
{
	$is_whitelisted = get_post_meta( $id, '_is_local_stock', true );
	#print_r($is_whitelisted);
	if ($is_whitelisted == 'yes')
	{
		#print_r("whitelisted");
		return true;
	} else {
		#print_r("not whitelisted");
		return false;
	}
}

################################################################################
# Update Post Date Upon successful Sync - Task 2
function run_sync()
{
  cleanup_whitelist();
  $sync_post_title = 'DO NOT DELETE - last successful sync date';
  $sync_post = retrieve_post($sync_post_title);
  $last_sync_date_gmt = $sync_post->post_content;
  $sync_post_id = $sync_post->ID;
  print_r($sync_post_id);
  #Get GMT time from wordpress
  $sync_start_date_gmt = $last_sync_date_gmt;
  #$sync_start_date_gmt = "2019-10-03 21:40:26"; # Date hardcoded for debugging only
  #print_r($sync_start_date_gmt);
  
  $sync_end_date = current_time('mysql');
  $sync_end_date_gmt = get_gmt_from_date( $sync_end_date , $format = 'Y-m-d H:i:s');
  #print_r($sync_end_date_gmt);
  #run sync using the given start and end dates
  $successfully_synced = update_stock_woocommerce($sync_start_date_gmt, $sync_end_date_gmt);
  trash_products_out_of_stock();
  # if sync was successful then update sync_post with last_sync_date_gmt.
  if ($successfully_synced == false)
  {
   # print_r("Sync could not complete successfully.");
  } else {
  #print_r(" Sync completed successfully.");
  update_post_content($sync_end_date_gmt,$sync_post_id);
  }  
}
#add_action('init','run_sync'); # run sync each time you refresh screen - for debugging

#################################################################################
# Manage local stock - Task 3

add_action( 'admin_menu', 'my_plugin_menu' );
add_action( 'admin_init', 'setup_sections' );
add_action( 'admin_init', 'setup_fields' );
add_action( 'admin_init', 'submit_local_stock', 10);

function submit_local_stock()
{
  if(isset($_POST['submit_local_stock_quantity']))
  {
    update_local_stock($_POST['sku'], $_POST['quantity'] );
   
  } 
  if(isset($_POST['delete_local_stock']))
  {
    clear_whitelist();
  }   
}

function my_plugin_menu(){
	add_submenu_page('edit.php?post_type=product','Local Stock', 'Local Stock',
	'manage_options', 'enter-local-stock','enter_local_stock');
	
	#add_options_page( 'Manage local stock', 'Murphys Magic Utility', 'manage_options',
	#'my-unique-identifier', 'my_plugin_options' );
}

function setup_sections(){
	add_settings_section( 'Update Stock', '', 'section_callback',
	 'enter_local_stock' );
}

function setup_fields() {
    add_settings_field( 'sku', 'SKU', 'field_callback1', 'enter_local_stock', 
    'Update Stock' );
    add_settings_field( 'quantity', 'Quantity', 'field_callback2', 'enter_local_stock', 
    'Update Stock' );
}

function section_callback( ) {
    echo 'Enter the SKU and quantity for local stock. Stock quantities displayed in store
     will reflect local stock levels for these products. When the products stock quantity 
     reaches zero, it will automatically syncronise with stock levels of Murphys Magic
      once again.';  
}

function field_callback1( ) {
    echo '<input name="sku" id="sku" type="integer" value="SKU" />';
	register_setting( 'Update Stock', 'sku' );
	
}
function field_callback2( ) {
    echo '<input name="quantity" id="quantity" type="integer" value="quantity" />';
	register_setting( 'Update Stock', 'quantity' );
}
function enter_local_stock(){?>
	<div class="wrap">
    <h2>Update local stock</h2>
    <form method="post" action="">
    <?php
    	#settings_fields( 'enter_local_stock' );
        do_settings_sections( 'enter_local_stock' );
        submit_button("Save changes", "primary", "submit_local_stock_quantity");
    ?>
    </form>
    <form method="post" action="">
    <?php
    	#settings_fields( 'reset_local_stock' );
        #do_settings_sections( 'clear_whitelist' );
        submit_button("Delete all local stock", "delete_local_stock",
         "delete_local_stock");
    ?>
    </form>
	</div>    
<?php
}
function clear_whitelist() #removes products whose local stock has dropped to zero
{
  #print_r("cleanup");
  $args = array(
    'post_type' => 'product',
    'meta_query' => array(
      array(
        'key' => '_is_local_stock',
        'value' => 'yes',
      )
    )
  );
  $products_local_stock_now_zero = get_posts( $args );
  #make an array of all items to be deleted from whitelist (and to be synced with 
  #murphy's magic again)

  foreach ( $products_local_stock_now_zero as $product ){
    $ID = ($product->ID);
    #print_r($ID);
    #$SKU = ????? may be useful here to sync product immediately with Murphys magic
    delete_post_meta($ID, '_is_local_stock');
    
    #print('All local stock have been deleted.');
  }
}
   
function update_local_stock(  $sku, $stock_quantity)
 {
  $product_id = wc_get_product_id_by_sku( $sku ); # get ID (key) from sku
  //print($product_id);
  if ($product_id == 0)
  {?>
	<div id="message" class="updated notice is-dismissible">
    <p> Product number <?php echo $sku ?> does not exist in Woocommerce shop. </p>
    </div> 		
  	<?php
  } else {
  	
  	# Update postmeta _manage_local_stock with 'yes'
  	update_metadata( 'post', $product_id, '_manage_stock', 'yes' );
  	# Create Wordpress post_meta in database to flag that stock is local
 	update_metadata( 'post', $product_id, '_is_local_stock', 'yes' );
  	# Update Woocommerce with local stock
  	$updated = wc_update_product_stock($product_id, $stock_quantity, 'set');
  	//print_r($stock_quantity);
  	//print_r($sku);
  	if($updated){ ?>
    	<div id="message" class="updated notice is-dismissible">
    	<p> Product <?php echo $sku ?> stock quantity has been updated to <?php echo $stock_quantity?>. </p>
    	</div>
    	<?php 
    }
  }
}

function trash_products_out_of_stock(){
 
  $args = array(
    'post_type' => 'product',
    'meta_query' => array(
      'relation' => 'AND',
      array(
        'key' => '_out_of_stock_date',
		'compare' => 'NOT EXISTS', // works! /// 
     	'value' => '' // This is ignored, but is necessary...        
      ),
      array(
        'key' => '_stock',
        'value' => '0'
      )
    )
  );
  $products_newly_out_of_stock = get_posts( $args );
  $current_time = current_time('timestamp');
  #print_r("Current time: ");
  #print_r($current_time);
  //print_r("***");
 
 
  foreach ( $products_newly_out_of_stock as $product ){
    $ID = ($product->ID);
    #print_r($ID);
    # Create Wordpress post_meta in database to flag that stock is 0
    update_metadata( 'post', $ID, '_out_of_stock_date', $current_time );
    }

// If out of stock date is set, and stock is > 0, delete _out_of_stock_date post meta
  $args = array(
    'post_type' => 'product',
    'meta_query' => array(
      'relation' => 'AND',
      array(
        'key' => '_out_of_stock_date',
		'compare' => 'EXISTS', // works! /// 
     	//'value' => '' // This is ignored, but is necessary...        
      ),
      array(
        'key' => '_stock',
        'value' => '0',
        'compare' => '>'
      )
    )
  );
  $products_back_in_stock = get_posts( $args );
  #print_r("Products being deleted: ");
  foreach ( $products_back_in_stock as $product ){
    $ID = ($product->ID);
    
   
    #print_r($ID);
    delete_post_meta($ID, '_out_of_stock_date');
  }

// If product has been out of stock for 30 days, then delete 
// If out of stock date is less than today - 30 (days), and stock is > 0, delete
//_product from database.
  $cut_off_period_in_days = 30;
  $cut_off_date = $current_time - $cut_off_period_in_days*24*3600;
  #print_r("cut_off_date: ");
  #print_r($cut_off_date);
  
  
  $args = array(
    'post_type' => 'product',
    'meta_query' => array(
      'relation' => 'AND',
      array(
        'key' => '_out_of_stock_date',
		'compare' => '<', // works! /// 
     	'value' => $cut_off_date,    
      )
    )
  );
  
  
  
  $products_to_delete = get_posts( $args );
  
  #print_r("Deleting Products: ");

  foreach ( $products_to_delete as $product ){
    $ID = ($product->ID);
    #print_r("Product ID: ");
    #print_r($ID);
    
    delete_post_meta($ID, '_out_of_stock_date');
    wp_trash_post( $ID );
  }  
}

#################################################################################
# Submit order to Murphys Magic - Task 9

//hook into woocommerce at payment completion
# all paid orders are placed on-hold by Woocommerce except for digital download items
add_action('woocommerce_order_status_on-hold', 'add_to_murphys_sales_order', 10, 1);
# all digital download items where payment is completed succesfully
add_action('woocommerce_payment_complete', 'add_to_murphys_sales_order', 10, 1);
# for help and more info: https://docs.woocommerce.com/document/managing-orders/

# write post to database to test if hook works.
#function add_to_murphys_sales_order(){
#  $my_post = array(
#      'post_title'    => 'HOOOOOK WORKS',
#      'post_content'  => 'hookie hookie',
#      'post_status'   => 'private'
#      );
#      $post_id = wp_insert_post( $my_post ); 
#}


function add_to_murphys_sales_order($order_id) {

	//////////////////////////////////////////////////////////
	date_default_timezone_set('UTC');//'America/Los_Angeles'
	
	$wsdl = get_option('MurphysMagic1_web_services_wsdl');
    $username = get_option('MurphysMagic1_web_services_username');
    $password = get_option('MurphysMagic1_web_services_password');
    $namespace = get_option('MurphysMagic1_web_services_namespace');

  
    $client = new SoapClient($wsdl,array('connection_timeout' => 60000));

	$header = new SoapHeader($namespace,'SoapAuthenticationHeader',$auth,false);

	$client->__setSoapHeaders(array($header));
	/////////////////////////////////////////////////////////

	//$order_id = 445;
	//get order details

	$order = wc_get_order( $order_id );
    $products = $order->get_items();
	
	
	$product_skus = array();
	$quantities = array();
	
	foreach($products as $prod)
	{
			
		$quantities[] = $prod['quantity'];
		$itemkey = $prod['product_id'];	//gets item key from the product object in WC 
		#order object (this product object does not contain sku)
		
		
		$product = wc_get_product($itemkey); // gets a full product object relating to 
		#itemkey (this product object does contain sku)
		$skus[] = $product->get_sku(); //finally gets sku
		
	}
		
	#var_dump($skus);
	#var_dump($quantities);
	
	//send product key and quantity arrays to murphys magic as new line in currently open 
	# sales order.
	// if no sales order is open, then a new one will be opened.
	try
	{
	
		$parameters = array('itemKeys' => $skus, 'quantities' => $quantities);
	    #var_dump($parameters);
		$result = $client->SubmitSalesOrderLineItems($parameters);
		#var_dump($result);
	   
	}
	catch (Exception $e)
	{
	  //  printf("Soap Fault: %s\n", $e->Reason);
		echo $e;
	}	
	
	#$response = print_r($result, true);
	#echo $response;

	//print_r("<p>Success: " . $result->SubmitSalesOrderLineItems->Success . "</p>");
	//print_r("<p>Message: " . $result->Message . "</p>");
}



#########################################################################################


class MurphysMagic1_Wordpress_Solution {
    
	public function __construct() {
		// For debugging, will display all $_POST values
		//$this->show_post_values();
		
		register_activation_hook( __FILE__, array($this, 'plugin_activation'));
		register_deactivation_hook( __FILE__, array($this, 'plugin_deactivation'));

		add_action('wp_ajax_murphys-woo-product-importer-ajax', array($this, 'render_ajax_action'));
		add_action('admin_menu', array($this, 'MurphysMagic1_admin_menu'));
		
		// create shortcode for downloads login
		if (get_option('MurphysMagic1_shortcodes')) {
			add_shortcode('murphys_downloads_login', array($this, 'MurphysMagic1_shortcode_downloads_login'));
		}
		
		//hook into woocommerce at payment completion
		if (get_option('MurphysMagic1_woo_download')) {
			add_action('woocommerce_payment_complete', 'MurphysMagic1_send_api_request', 10, 1);
			include(plugin_dir_path(__FILE__) . 'murphys-woo-checkout.php');
		}
		
		// Removes Admin bar for all but Admins (so logged in users with Customer role don't see it')
		if(get_option('MurphysMagic1_admin_bar_hide')) {
			add_action('after_setup_theme', 'remove_admin_bar');
			function remove_admin_bar() {
				if (!current_user_can('administrator') && !is_admin()) {
				  show_admin_bar(false);
				}
			}
		} else { // If Admin Bar is being displayed
			// Removes WP logo and Search from Admin bar
			add_action('admin_bar_menu', 'remove_wp_logo1', 999);
			function remove_wp_logo1( $wp_admin_bar ) {
				$wp_admin_bar->remove_node('wp-logo');
				$wp_admin_bar->remove_node('search');
			}
		}
		




	}
	
	public function plugin_activation() {
		if(!get_option('MurphysMagic1_web_services_wsdl'))
			update_option('MurphysMagic1_web_services_wsdl', 'http://ws.MurphysMagic.com/V4.asmx?WSDL');

		if(get_option('MurphysMagic1_web_services_namespace'))
			update_option('MurphysMagic1_web_services_namespace', 'http://webservices.murphysmagicsupplies.com/');

		if(!get_option('MurphysMagic1_downloads_api_url'))
			update_option('MurphysMagic1_downloads_api_url', 'http://downloads.MurphysMagic.com/api/');

		if(!get_option('MurphysMagic1_admin_advanced_tools'))
			update_option('MurphysMagic1_admin_advanced_tools', false);
	}

	public function plugin_deactivation() {
		// DO NOTHING...
	}

	public function MurphysMagic1_admin_menu() {
		#add_menu_page('Murphy\'s Magic (1.2.10)', 'Murphy\'s Magic (1.2.10)', 'manage_options', 'MurphysMagic1-main', array($this, 'MurphysMagic1_main'), '', '4.007');
		

		add_submenu_page('edit.php?post_type=product', __( 'Import Product Range', 'woo-product-importer' ), __( 'Import Product Range', 'woo-product-importer' ), 'manage_options', 'MurphysMagic1-main', array($this, 'render_admin_action'));

		


		//add_submenu_page('MurphysMagic1-main', __( 'Upload Product CSV', 'woo-product-importer' ), __( 'Upload Product CSV', 'woo-product-importer' ), 'manage_options', 'MurphysMagic1-main-upload', array($this, 'render_admin_action_upload'));

		#add_submenu_page('MurphysMagic1-main', __( 'Discontinued Items', 'woo-product-importer' ), __( 'Discontinued Items', 'woo-product-importer' ), 'manage_options', 'MurphysMagic1-main-discontinued', array($this, 'render_admin_action2'));

		//add_submenu_page('MurphysMagic1-main', __( 'Update Products', 'woo-product-importer' ), __( 'Update Products', 'woo-product-importer' ), 'manage_options', 'MurphysMagic1-main-quantities', array($this, 'render_admin_action3'));

		add_submenu_page('edit.php?post_type=product', 'Import Settings', 'Settings', 'manage_options', 'MurphysMagic1-settings', array($this, 'MurphysMagic1_settings_page'));
		
		if(get_option('MurphysMagic1_admin_advanced_tools')) {
			#add_submenu_page('MurphysMagic1-main', 'Advanced Tools', 'Advanced Tools', 'manage_options', 'MurphysMagic1-advanced-tools', array($this, 'MurphysMagic1_advanced_tools'));
		}
		
		#add_submenu_page('MurphysMagic1-main', 'Help / About', 'Help / About', 'manage_options', 'MurphysMagic1-help', array($this, 'MurphysMagic1_help_about'));
	}
    
	public function render_ajax_action() {
		require_once(plugin_dir_path(__FILE__)."importer/woo-product-importer-ajax.php");
		die(); // this is required to return a proper result
	}
    
	public function render_admin_action() {
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'start';
		require_once(plugin_dir_path(__FILE__).'importer/woo-product-importer-common.php');
		require_once(plugin_dir_path(__FILE__)."importer/woo-product-importer-{$action}.php");
	}
    
	public function render_admin_action2() {
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'discontinued';
		require_once(plugin_dir_path(__FILE__).'importer/woo-product-importer-common.php');
		require_once(plugin_dir_path(__FILE__)."importer/woo-product-importer-{$action}.php");
	}
    
	public function render_admin_action3() {
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'quantities';
		require_once(plugin_dir_path(__FILE__).'importer/woo-product-importer-common.php');
		require_once(plugin_dir_path(__FILE__)."importer/woo-product-importer-{$action}.php");
	}
    
	public function MurphysMagic1_help_about() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		include(plugin_dir_path(__FILE__) . 'helpabout.php');
	}

	public function MurphysMagic1_settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		include(plugin_dir_path(__FILE__) . 'murphys-magic-settings.php');
	}

	public function MurphysMagic1_advanced_tools() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		include(plugin_dir_path(__FILE__) . 'tools/MurphysMagic1_advanced_tools.php');
	}

	public function MurphysMagic1_main() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		//include(plugin_dir_path(__FILE__) . 'main.php');
	}

	public function MurphysMagic1_shortcode_downloads_login() {
		$downloads_iframe_url = get_option('MurphysMagic1_downloads_iframe_url');
		return "<iframe src='$downloads_iframe_url' height='1000' width='100%' frameborder='0'></iframe>";
	}
	
	public function show_post_values() {
		if(!empty($_POST)):
			$fields = array();
			$values = array();
			foreach($_POST as $field => $value) {
				$fields[] = $field;
				$values[] = $value;
			}
			echo "<pre>";
			print_r($fields);
			print_r($values);
			echo "</pre>";
		endif;
	}


}





































//////////////////////////////////////// task 11 /////////////////////////////////////////

//add_action('wp_loaded', 'new_product_sync');


function new_product_sync()
{
// move back to root of WP install path
$path = dirname(dirname(dirname(dirname(__FILE__))));
include_once($path . '/wp-load.php');

$ws_url = get_option('murphysmagic1_web_services_wsdl');
$username = get_option('murphysmagic1_web_services_username');
$password = get_option('murphysmagic1_web_services_password');
$namespace = get_option('murphysmagic1_web_services_namespace');

if ($ws_url == '' || $username == '' || $password == '' || $namespace =='') {
    echo 'Error: Invalid username, password, web services URL, or namespace.';
    exit();
}

$header = new SoapHeader($namespace, "SoapAuthenticationHeader", array(
    'Username' => $username,
    'Password' => $password,
), FALSE);



$max_execution_time = 6000; //60 = 1 minute
ini_set('max_execution_time', $max_execution_time);

//place this before any script you want to calculate time
$time_start = microtime(true);

$wsV4URL = str_replace("?WSDL", '', get_option('murphysmagic1_web_services_wsdl'));

$video_embed_disable = get_option('murphysmagic1_video_embed_disable');
$video_embed_ssl = get_option('murphysmagic1_video_embed_ssl');
if($video_embed_ssl) {
	$video_embed_url = 'https://www.murphysmagic.com/videoembed/defaults.aspx';
} else {
	$video_embed_url = 'http://www.murphysmagic.com/videoembed/default.aspx';
}

$price_factor = get_option('murphysmagic1_price_factor');


try {
    $client = new SOAPClient($ws_url);
    $client->__setSoapHeaders(array($header));
    
    /*
    $startdate = $_POST['startdate'] . "T00:00:00";
    $enddate = $_POST['enddate'] . "T23:59:59";
    $datestamp = $_POST['startdate'] . "-to-" . $_POST['enddate'];
	$get_physical = $_POST['get_physical'];
	$get_downloads = $_POST['get_downloads'];
	$discontinued = ($_POST['discontinued'] == 'true') ? true : false;
	$quantities = ($_POST['quantities'] == 'true') ? true : false;
	*/
	
	$startdate = "2019-02-15";
    $enddate = "2019-02-20";
    $datestamp = $startdate. "-to-" . $enddate;
	
	
	$get_physical = true;
	$get_downloads = true;
	$discontinued = false;
	$quantities = false;
	
	if($discontinued) {
		$webserviceP = "GetInactiveInventory";
		$webserviceResultP = "GetInactiveInventoryResult";
		$webserviceD = "GetInactiveDownloads";
		$webserviceResultD = "GetInactiveDownloadsResult";
		$visibility = 'hidden';
		$discontinued_quantity = get_option('murphysmagic1_discontinued_quantity');
		$admin_page = "murphysmagic1-main-discontinued";
		update_option("murphysmagic1_discontinued_startdate", $startdate);
		update_option("murphysmagic1_discontinued_enddate", $enddate);
	} elseif($quantities) {
		$webserviceP = "GetInventoryItemsAddedBetween";
		$webserviceResultP = "GetInventoryItemsAddedBetweenResult";
		$webserviceD = "GetDownloadsAddedBetween";
		$webserviceResultD = "GetDownloadsAddedBetweenResult";
		$admin_page = "murphysmagic1-main-quantities";
		update_option("murphysmagic1_update_startdate", $startdate);
		update_option("murphysmagic1_update_enddate", $enddate);
	} else {
		$webserviceP = "GetInventoryItemsAddedBetween";
		$webserviceResultP = "GetInventoryItemsAddedBetweenResult";
		$webserviceD = "GetDownloadsAddedBetween";
		$webserviceResultD = "GetDownloadsAddedBetweenResult";
		$visibility = 'visible';
		//$admin_page = "murphysmagic1-main-import";
		$admin_page = "murphysmagic1-main";
		update_option("murphysmagic1_import_startdate", $startdate);
		update_option("murphysmagic1_import_enddate", $enddate);
	}
    
    
    $startdate = strtotime($startdate);
	$enddate = strtotime($enddate);
    
    $load_pagesP = TRUE;
    $pageP = 0;
    $page_item_countP = 0;
    $total_countP = 0;

	$load_pagesD = TRUE;
	$pageD = 0;
	$page_item_countD = 0;
	$total_countD = 0;
	
	$product_html = "";
    
    // Let's make a CSV file!
    if($discontinued) {
		$filename = "importer/csv/discontinued-$datestamp-".time().".csv";
	} elseif($quantities) {
		$filename = "importer/csv/updates-$datestamp-".time().".csv";
	} else {
		$filename = "importer/csv/import-$datestamp-".time().".csv";
	}
    $filepath = plugin_dir_path(__FILE__) . $filename;

	$csv_handler = fopen($filepath, 'w');
	$csv_header = array('sku', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'category', 'tags', '_manage_stock', '_stock_status', 'backorders', 'stock', 'price', 'wholesale', '_sale_price', 'weight', 'length', 'width', 'height', 'images', '_tax_status', '_tax_class', 'visibility', 'featured', 'virtual');
	//$csv_header = array('sku', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'category', 'tags', '_manage_stock', '_stock_status', 'backorders', 'stock', 'wholesale', '_sale_price', 'weight', 'length', 'width', 'height', 'images', '_tax_status', '_tax_class', 'visibility', 'featured', 'virtual');
	fputcsv($csv_handler, $csv_header);

	/*
    echo "<table class='datatable'>";
    echo "<tr>";
        echo "<th>SKU</th>";
//        if(!$quantities)
//        	echo "<th>Thumbnail</th>";
        echo "<th>Title</th>";
        echo "<th>Quantity Available</th>";
        echo "<th>Suggested Retail Price</th>";
        if(!$quantities) {
    	    echo "<th>Wholesale Price</th>";
        	echo "<th>Date Added</th>";
        	echo "<th>Categories</th>";
        }
    echo "</tr>";
    */
	

if($get_physical == "true") {
    ////// WHILE LOOP WILL START HERE
    while($load_pagesP) {
        $load_pagesP = FALSE;
        $pageP++;
        $parametersP = array('startDate' => $startdate, 'endDate' => $enddate, 'page' => $pageP);

		$returnP = $client->$webserviceP($parametersP);
		#var_dump($returnP)

        if ($returnP->$webserviceResultP->Success) {
            if(is_array($returnP->items->InventoryItem)){
                $resultsP = $returnP->items->InventoryItem;
            } elseif(!empty($returnP->items->InventoryItem)) {
                $resultsP = array('InventoryItem' => $returnP->items->InventoryItem);
            }
            
            if(isset($resultsP)) {
            foreach ($resultsP as $item) {
                $total_countP++;
                $page_item_countP++;
	        		
                $product_html .= "<tr>";
                	$sku = $item->InternalId;
                    $product_html .= "<td><a href='http://www.murphysmagic.com/Product.aspx?id=$sku' target='_blank'>$sku</a></td>";
                    if(!$discontinued && !$quantities) {
	                    $image = "http://www.murphysmagicsupplies.com/images/" . $item->ImageFileName;
    	                //$product_html .= "<td><a href='$image' target='_blank'><img src='http://www.murphysmagicsupplies.com/images/$item->ImageThumbnailFileName' border='0' alt='' /></a></td>";
					} elseif($quantities) {
						// DO NOTHING HERE
					} else {
	                    //$image = "http://www.murphysmagicsupplies.com/images/" . $item->ImageFileName;
    	                //$product_html .= "<td><a href='$image' target='_blank'><img src='http://www.murphysmagicsupplies.com/images/$item->ImageThumbnailFileName' border='0' alt='' /></a></td>";
    	                $image = '';
					}
					$title = $item->Title;
                    $product_html .= "<td>$title</td>";
					if(!$discontinued && !$quantities) {
						$html_description = $item->HTMLDescription;
					} else {
						$html_description = '';
					}
					$stock = $item->QuantityAvailable;
					if($stock > 0) {
						$stock_status = 'instock';
					} else {
						$stock_status = 'outofstock';
					}
					if(($discontinued && $stock < $discontinued_quantity) || !$discontinued) {
	                    $product_html .= "<td>$stock</td>";
					} else {
	                    $product_html .= "<td style='background-color: #b3ffaf;'>*$stock</td>";
	                    $discontinued_product_still_available = true;
	                    $total_countP--;
					}
                    $wholesale = number_format($item->WholesalePrice, 2);
                    if(floatval($price_factor) == 1 || floatval($price_factor) == 0) {
	                    $price = number_format($item->SuggestedRetailPrice, 2);
	                    $product_html .= "<td>$" . $price . " USD</td>";
					} else {
	                    $price = number_format($item->SuggestedRetailPrice * $price_factor, 2);
	                    $product_html .= "<td>" . $price . " (adjusted)</td>";
					}
/////////////////////////////////////////////////////////////////
					if(!$quantities) {
						$weight = $item->Weight;
						$width = $item->Width;
						$length = $item->Length;
						$height = $item->Height;
	                    $product_html .= "<td>$" . number_format($item->WholesalePrice, 2) . " USD</td>";
	                    $product_html .= "<td>" . WSTimeConvert($item->DateAdded) . "</td>";
	                    $product_html .= "<td>";
	                        $categoriesnew = '';
	                        foreach($item->CategoriesNew as $categorynew) {
	                            if(is_array($categorynew)){
	                                foreach($categorynew as $cat) {
	                                    $categoriesnew .= $cat . "|";
	                                }
	                            } else {
	                                $categoriesnew = $categorynew;
	                            }
	                        }
	                        $categoriesnew = trim($categoriesnew,'|');
	                        if($categoriesnew == '')
	                        	$categoriesnew = "Other";
	                        if($discontinued)
	                        	$categoriesnew = "Discontinued";
	                        $product_html .= $categoriesnew;
	                        $tags = str_replace(',', '|', $categoriesnew);
							$tags = explode("|", $tags);
							$tags = array_unique($tags);
							$tags = implode("|", $tags);
					}
/////////////////////////////////////////////////////////////////

                    $product_html .= "</td>";
/////////////////////////////////////////////////////////////////
					if(!$quantities) {
	                    $videofilename = '';
	                    $has_trailer = false;
	                    if(isset($item->Videos->Video)):
	                        foreach($item->Videos->Video as $video) {
	                           if(is_object($video) || is_array($video)) {
	                                // if more than one video file is available
	                                foreach($video as $file){
	                                    $videofilename .= "<a href='http://www.murphysmagicsupplies.com/video/clips/$file' target='_blank'>$file</a> || ";
	                                }
	                            } else {
	                                $videofilename .= "<a href='http://www.murphysmagicsupplies.com/video/clips/$video' target='_blank'>$video</a>";
	                            }
	                        }
	                        $has_trailer = ($videofilename == '' ? false : true);
	                        endif;
					}
/////////////////////////////////////////////////////////////////
                $product_html .= "</tr>";
            
            if(!$quantities) {
	            // Check if we need to add a trailer to the HTML description
	            if($has_trailer && !$html_description == '') {
					if(!$video_embed_disable) {
						$html_description = '<iframe class="mms-video-player" width="510" height="311" frameborder="0" scrolling="no" src="'.$video_embed_url.'?id='.$sku.'"></iframe><p></p>' . $html_description;
					}
				}
	            
	            if(!($discontinued && $discontinued_quantity < $stock)) {
		            $csv_line = array($sku, 'publish', $title, $html_description, '', $categoriesnew, $tags, 'yes', $stock_status, 'no', $stock, $price, $wholesale, '', $weight, $width, $length, $height, $image, 'none', '', $visibility, '', 'no');
	    	        fputcsv($csv_handler, $csv_line);
				}
			} else {
				$csv_line = array($sku, '', '', '', '', '', '', '', $stock_status, '', $stock, $price, '', '', '', '', '', '', '', '', '', '', '');
	    	    fputcsv($csv_handler, $csv_line);
			}
            
            }
			}
				
            if($page_item_countP == 100) {
                    $page_item_countP = 0;
                    $load_pagesP = TRUE;	
            }
			
        } else {
            echo 'Error: ';
            echo $return->$webserviceResultP->ErrorMessages->string;
        }
	
			
    // WHILE LOOP STOPS HERE
    }
}	// close if(get_physical)





if($get_downloads == "true") {
    ////// WHILE LOOP WILL START HERE
    while($load_pagesD) {
        $load_pagesD = FALSE;
        $pageD++;
        $parameters = array('startDate' => $startdate, 'endDate' => $enddate, 'page' => $pageD);

        $return = $client->$webserviceD($parameters);

        if ($return->$webserviceResultD->Success) {
            if(is_array($return->items->DownloadItems)){
                $results = $return->items->DownloadItems;
            } elseif(!empty($return->items->DownloadItems)) {
                $results = array('DownloadItems' => $return->items->DownloadItems);
            }
            
            if(isset($results)) {
            foreach ($results as $item) {
                $total_countD++;
                $page_item_countD++;
	        		
                $product_html .= "<tr>";
                	$sku = $item->InternalId;
                    $product_html .= "<td><a href='http://www.murphysmagic.com/Streaming/Product.aspx?id=$sku' target='_blank'>$sku</a></td>";
                    if(!$quantities) {
	                    $image = "http://www.murphysmagicsupplies.com/images/" . $item->ImageFileName;
    	                //$product_html .= "<td><a href='$image' target='_blank'><img src='http://www.murphysmagicsupplies.com/images/$item->ImageThumbnailFileName' border='0' alt='' /></a></td>";
					} else {
						// DO NOTHING HERE
					}
					$title = $item->Title;
                    $product_html .= "<td>$title</td>";
					if(!$quantities) {
						$html_description = $item->HTMLDescription;
					} else {
						$html_description = '';
					}
					$stock = $item->QuantityAvailable;
					$stock = "n/a";
                    $product_html .= "<td>$stock</td>";
                    $wholesale = number_format($item->WholesalePrice, 2);
                    if(floatval($price_factor) == 1 || floatval($price_factor) == 0) {
	                    $price = number_format($item->SuggestedRetailPrice, 2);
	                    $product_html .= "<td>$" . $price . " USD</td>";
					} else {
	                    $price = number_format($item->SuggestedRetailPrice * $price_factor, 2);
	                    $product_html .= "<td>" . $price . " (adjusted)</td>";
					}
/////////////////////////////////////////////////////////////////
					if(!$quantities) {
						$weight = $item->Weight;
						$weight = '';
	                    $product_html .= "<td>$" . number_format($item->WholesalePrice, 2) . " USD</td>";
	                    $product_html .= "<td>" . WSTimeConvert($item->DateAdded) . "</td>";
	                    $product_html .= "<td>";
	                        $categories = 'Digital Downloads|';
	                        foreach($item->Categories as $category) {
	                            if(is_array($category)){
	                                foreach($category as $cat) {
	                                    $categories .= $cat . "|";
	                                }
	                            } else {
	                                $categories .= $category;
	                            }
	                        }
	                        //$categories = '';
	                        $categories = trim($categories,'|');
	                        if($categories == 'Digital Downloads') {
	                        	$categories .= "|Other";
							}
	                        if($discontinued)
	                        	$categories = "Discontinued";
	                        $product_html .= $categories;
	                        $tags = str_replace(',', '|', $categories);
							$tags = explode("|", $tags);
							$tags = array_unique($tags);
							$tags = implode("|", $tags);
					}
/////////////////////////////////////////////////////////////////

                    $product_html .= "</td>";
/////////////////////////////////////////////////////////////////
                    if(!$quantities) {
	                    $videofilename = '';
	                    $has_trailer = false;
	                    if(isset($item->Videos->Video)):
	                        foreach($item->Videos->Video as $video) {
	                           if(is_object($video) || is_array($video)) {
	                                // if more than one video file is available
	                                foreach($video as $file){
	                                    $videofilename .= "<a href='http://www.murphysmagicsupplies.com/video/clips/$file' target='_blank'>$file</a> || ";
	                                }
	                            } else {
	                                $videofilename .= "<a href='http://www.murphysmagicsupplies.com/video/clips/$video' target='_blank'>$video</a>";
	                            }
	                        }
	                        $has_trailer = ($videofilename == '' ? false : true);
	                        endif;
					}
/////////////////////////////////////////////////////////////////
                $product_html .= "</tr>";
            
            if(!$quantities) {
	            // Check if we need to add a trailer to the HTML description
	            if($has_trailer) {
	            	if(!$video_embed_disable) {
						$html_description = '<iframe class="mms-video-player" width="510" height="311" frameborder="0" scrolling="no" src="'.$video_embed_url.'?id='.$sku.'"></iframe><p></p>' . $html_description;
					}
				}
				
	            $csv_line = array($sku, 'publish', $title, $html_description, '', $categories, $tags, 'no', 'instock', 'no', $stock, $price, $wholesale, '', $weight, '', '', '', $image, 'none', '', $visibility, '', 'yes');
	            fputcsv($csv_handler, $csv_line);
			} else {
				$csv_line = array($sku, '', '', '', '', '', '', '', $stock_status, '', $stock, $price, '', '', '', '', '', '', '', '', '', '', '');
	    	    fputcsv($csv_handler, $csv_line);
			}
            
            }
            }
				
            if($page_item_countD == 100) {
                    $page_item_countD = 0;
                    $load_pagesD = TRUE;	
            }
			
        } else {
            echo 'Error: ';
            echo $return->$webserviceResultD->ErrorMessages->string;
        }
	
			
    // WHILE LOOP STOPS HERE
    }
}	// close if(get_downloads)

    // Finish writing the CSV file
    fclose($csv_handler);




} catch (Exception $e) {
    echo "Error occured: " . $e;
}
























//////////////////////////////////////////////////////////////////////////////////////////
// now we read all, now its time to write it to the CSV
	ini_set("auto_detect_line_endings", true);
    

    $post_data = array(
        'uploaded_file_path' => $filepath,
        'header_row' => 1,
        'limit' =>'',
        'offset' => 0,
        'map_to' => array(
        	'_sku',
        	'post_status',
        	'post_title',
            'post_content',
            'post_excerpt',
            'product_cat_by_name',
            'product_tag_by_name',
            '_manage_stock',
            '_stock_status',
            '_backorders',
            '_stock',
            '_regular_price',
            'do_not_import',
            '_sale_price',
            '_weight',
            '_length',
            '_width',
            '_height',
            'product_image_by_url',
            '_tax_status',
            '_tax_class',
            '_visibility',
            '_featured',
            '_virtual',
            ),
        'custom_field_name' => array(
            'sku',
            'post_status',
            'post_title',
            'post_content',
            'post_excerpt',
            'category',
            'tags',
            '_manage_stock',
            '_stock_status',
            'backorders',
            'stock',
            'price',
            'wholesale',
            '_sale_price',
            'weight',
            'length',
            'width',
            'height',
            'images',
            '_tax_status',
            '_tax_class',
            'visibility',
            'featured',
            'virtual',
            ),
            
        'custom_field_visible' => array(1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1),
        'product_image_set_featured' => array(1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1),
        'product_image_skip_duplicates' => array(1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1),
        'post_meta_key' => array( 
        	'sku',
			'post_status',
			'post_title',
			'post_content',
			'post_excerpt',
			'category',
			'tags',
			'_manage_stock',
			'_stock_status',
			'backorders',
			'stock',
			'price',
			'wholesale',
			'_sale_price',
			'weight',
			'length',
			'width',
			'height',
			'images',
			'_tax_status',
			'_tax_class',
			'visibility',
			'featured',
			'virtual'
            ),
        'user_locale' => '',
        'import_csv_separator' => ',',
        'import_csv_hierarchy_separator' => '/'
    );

if(isset($post_data['uploaded_file_path'])) {

        setlocale(LC_ALL, $post_data['user_locale']);

        $error_messages = array();

        //now that we have the file, grab contents
        $temp_file_path = $post_data['uploaded_file_path'];
        $handle = fopen( $temp_file_path, 'r' );
        $import_data = array();

        if ( $handle !== FALSE ) {
            while ( ( $line = fgetcsv($handle, 0, $post_data['import_csv_separator']) ) !== FALSE ) {
                $import_data[] = $line;
            }
            fclose( $handle );
        } else {
            $error_messages[] = __( 'Could not open CSV file.', 'woo-product-importer' );
        }

        if(sizeof($import_data) == 0) {
            $error_messages[] = __( 'No data found in CSV file.', 'woo-product-importer' );
        }

        //discard header row from data set, if we have one
        if(intval($post_data['header_row']) == 1) array_shift($import_data);

        //total size of data to import (not just what we're doing on this pass)
        $row_count = sizeof($import_data);

        //slice down our data based on limit and offset params
        $limit = intval($post_data['limit']);
        $offset = intval($post_data['offset']);
        if($limit > 0 || $offset > 0) {
            $import_data = array_slice($import_data, $offset , ($limit > 0 ? $limit : null), true);
        }

        //a few stats about the current operation to send back to the browser.
        $rows_remaining = ($row_count - ($offset + $limit)) > 0 ? ($row_count - ($offset + $limit)) : 0;
        $insert_count = ($row_count - $rows_remaining);
        $insert_percent = number_format(($insert_count / $row_count) * 100, 1);

        //array that will be sent back to the browser with info about what we inserted.
        $inserted_rows = array();

        //this is where the fun begins
        foreach($import_data as $row_id => $row) {

            //unset new_post_id
            $new_post_id = null;

            //array of imported post data
            $new_post = array();

            //set some defaults in case the post doesn't exist
            $new_post_defaults = array();
            $new_post_defaults['post_type'] = 'product';
            $new_post_defaults['post_status'] = 'publish';
            $new_post_defaults['post_title'] = '';
            $new_post_defaults['post_content'] = '';
            $new_post_defaults['menu_order'] = 0;

            //array of imported post_meta
            $new_post_meta = array();

            //default post_meta to use if the post doesn't exist
            $new_post_meta_defaults = array();
            $new_post_meta_defaults['_visibility'] = 'visible';
            $new_post_meta_defaults['_featured'] = 'no';
            $new_post_meta_defaults['_weight'] = 0;
            $new_post_meta_defaults['_length'] = 0;
            $new_post_meta_defaults['_width'] = 0;
            $new_post_meta_defaults['_height'] = 0;
            $new_post_meta_defaults['_sku'] = '';
            $new_post_meta_defaults['_stock'] = '';
            $new_post_meta_defaults['_sale_price'] = '';
            $new_post_meta_defaults['_sale_price_dates_from'] = '';
            $new_post_meta_defaults['_sale_price_dates_to'] = '';
            $new_post_meta_defaults['_tax_status'] = 'taxable';
            $new_post_meta_defaults['_tax_class'] = '';
            $new_post_meta_defaults['_purchase_note'] = '';
            $new_post_meta_defaults['_downloadable'] = 'no';
            $new_post_meta_defaults['_virtual'] = 'no';
            $new_post_meta_defaults['_backorders'] = 'no';

            //stores tax and term ids so we can associate our product with terms and taxonomies
            //this is a multidimensional array
            //format is: array( 'tax_name' => array(1, 3, 4), 'another_tax_name' => array(5, 9, 23) )
            $new_post_terms = array();

            //a list of woocommerce "custom fields" to be added to product.
            $new_post_custom_fields = array();
            $new_post_custom_field_count = 0;

            //a list of image URLs to be downloaded.
            $new_post_image_urls = array();

            //a list of image paths to be added to the database.
            //$new_post_image_urls will be added to this array later as paths once they are downloaded.
            $new_post_image_paths = array();

            //keep track of any errors or messages generated during post insert or image downloads.
            $new_post_errors = array();
            $new_post_messages = array();

            //track whether or not the post was actually inserted.
            $new_post_insert_success = false;

            foreach($row as $key => $col) {
                $map_to = $post_data['map_to'][$key];

                //skip if the column is blank.
                //useful when two CSV cols are mapped to the same product field.
                //you would do this to merge two columns in your CSV into one product field.
                if(strlen($col) == 0) {
                    continue;
                }

                //validate col value if necessary
                switch($map_to) {
                    case '_downloadable':
                    case '_virtual':
                    case '_manage_stock':
                    case '_featured':
                        if(!in_array($col, array('yes', 'no'))) continue;
                        break;

                    case 'comment_status':
                    case 'ping_status':
                        if(!in_array($col, array('open', 'closed'))) continue;
                        break;

                    case '_visibility':
                        if(!in_array($col, array('visible', 'catalog', 'search', 'hidden'))) continue;
                        break;

                    case '_stock_status':
                        if(!in_array($col, array('instock', 'outofstock'))) continue;
                        break;

                    case '_backorders':
                        if(!in_array($col, array('yes', 'no', 'notify'))) continue;
                        break;

                    case '_tax_status':
                        if(!in_array($col, array('taxable', 'shipping', 'none'))) continue;
                        break;

                    case '_product_type':
                        if(!in_array($col, array('simple', 'variable', 'grouped', 'external'))) continue;
                        break;
                }

                //prepare the col value for insertion into the database
                switch($map_to) {

                    //post fields
                    case 'post_title':
                    case 'post_content':
                    case 'post_excerpt':
                    case 'post_status':
                    case 'comment_status':
                    case 'ping_status':
                        $new_post[$map_to] = $col;
                        break;

                    //integer post fields
                    case 'menu_order':
                        //remove any non-numeric chars
                        $col_value = preg_replace("/[^0-9]/", "", $col);
                        if($col_value == "") continue;

                        $new_post[$map_to] = $col_value;
                        break;

                    //integer postmeta fields
                    case '_stock':
                    case '_download_expiry':
                    case '_download_limit':
                        //remove any non-numeric chars
                        $col_value = preg_replace("/[^0-9]/", "", $col);
                        if($col_value == "") continue;

                        $new_post_meta[$map_to] = $col_value;
                        break;

                    //float postmeta fields
                    case '_weight':
                    case '_length':
                    case '_width':
                    case '_height':
                    case '_regular_price':
                    case '_sale_price':
                        //remove any non-numeric chars except for '.'
                        $col_value = preg_replace("/[^0-9.]/", "", $col);
                        if($col_value == "") continue;

                        $new_post_meta[$map_to] = $col_value;
                        break;

                    //sku
                    case '_sku':
                        $col_value = trim($col);
                        if($col_value == "") continue;

                        $new_post_meta[$map_to] = $col_value;
                        break;

                    //file_path(s)
                    case '_file_path':
                    case '_file_paths':
                        if(!is_array($new_post_meta['_file_paths'])) $new_post_meta['_file_paths'] = array();

                        $new_post_meta['_file_paths'][md5($col)] = $col;
                        break;

                    //all other postmeta fields
                    case '_tax_status':
                    case '_tax_class':
                    case '_visibility':
                    case '_featured':
                    case '_downloadable':
                    case '_virtual':
                    case '_stock_status':
                    case '_backorders':
                    case '_manage_stock':
                    case '_button_text':
                    case '_product_url':
                        $new_post_meta[$map_to] = $col;
                        break;

                    case 'post_meta':
                        $new_post_meta[$post_data['post_meta_key'][$key]] = $col;
                        break;

                    case '_product_type':
                        //product_type is saved as both post_meta and via a taxonomy.
                        $new_post_meta[$map_to] = $col;

                        $term_name = $col;
                        $tax = 'product_type';
                        $term = get_term_by('name', $term_name, $tax, 'ARRAY_A');

                        //if we got a term, save the id so we can associate
                        if(is_array($term)) {
                            $new_post_terms[$tax][] = intval($term['term_id']);
                        }

                        break;

                    case 'product_cat_by_name':
                    case 'product_tag_by_name':
                    case 'product_shipping_class_by_name':
                        $tax = str_replace('_by_name', '', $map_to);
                        $term_paths = explode('|', $col);
                        foreach($term_paths as $term_path) {

                            $term_names = explode($post_data['import_csv_hierarchy_separator'], $term_path);
                            $term_ids = array();

                            for($depth = 0; $depth < count($term_names); $depth++) {

                                $term_parent = ($depth > 0) ? $term_ids[($depth - 1)] : '';
                                $term = term_exists($term_names[$depth], $tax, $term_parent);

                                //if term does not exist, try to insert it.
                                if( $term === false || $term === 0 || $term === null) {
                                    $insert_term_args = ($depth > 0) ? array('parent' => $term_ids[($depth - 1)]) : array();
                                    $term = wp_insert_term($term_names[$depth], $tax, $insert_term_args);
                                    delete_option("{$tax}_children");
                                }

                                if(is_array($term)) {
                                    $term_ids[$depth] = intval($term['term_id']);
                                } else {
                                    //uh oh.
                                    $new_post_errors[] = "Couldn't find or create {$tax} with path {$term_path}.";
                                    break;
                                }
                            }

                            //if we got a term at the end of the path, save the id so we can associate
                            if(array_key_exists(count($term_names) - 1, $term_ids)) {
                                $new_post_terms[$tax][] = $term_ids[(count($term_names) - 1)];
                            }
                        }
                        break;

                    case 'product_cat_by_id':
                    case 'product_tag_by_id':
                    case 'product_shipping_class_by_id':
                        $tax = str_replace('_by_id', '', $map_to);
                        $term_ids = explode('|', $col);
                        foreach($term_ids as $term_id) {
                            //$term = get_term_by('id', $term_id, $tax, 'ARRAY_A');
                            $term = term_exists($term_id, $tax);

                            //if we got a term, save the id so we can associate
                            if(is_array($term)) {
                                $new_post_terms[$tax][] = intval($term['term_id']);
                            } else {
                                $new_post_errors[] = "Couldn't find {$tax} with ID {$term_id}.";
                            }

                        }
                        break;

                    case 'custom_field':
                        $field_name = $post_data['custom_field_name'][$key];
                        $field_slug = sanitize_title($field_name);
                        $visible = intval($post_data['custom_field_visible'][$key]);

                        $new_post_custom_fields[$field_slug] = array (
                            "name" => $field_name,
                            "value" => $col,
                            "position" => $new_post_custom_field_count++,
                            "is_visible" => $visible,
                            "is_variation" => 0,
                            "is_taxonomy" => 0
                        );
                        break;

                    case 'product_image_by_url':
                        $image_urls = explode('|', $col);
                        if(is_array($image_urls)) {
                            $new_post_image_urls = array_merge($new_post_image_urls, $image_urls);
                        }

                        break;

                    case 'product_image_by_path':
                        $image_paths = explode('|', $col);
                        if(is_array($image_paths)) {
                            foreach($image_paths as $image_path) {
                                $new_post_image_paths[] = array(
                                    'path' => $image_path,
                                    'source' => $image_path
                                );
                            }
                        }

                        break;
                }
            }

            //set some more post_meta and parse things as appropriate

            //set price to sale price if we have one, regular price otherwise
            $new_post_meta['_price'] = array_key_exists('_sale_price', $new_post_meta) ? $new_post_meta['_sale_price'] : $new_post_meta['_regular_price'];

            //check and set some inventory defaults
            if(array_key_exists('_stock', $new_post_meta)) {

                //set _manage_stock to yes if not explicitly set by CSV
                if(!array_key_exists('_manage_stock', $new_post_meta)) {
                    $new_post_meta['_manage_stock'] = 'yes';
                }
                //set _stock_status based on _stock if not explicitly set by CSV
                if(!array_key_exists('_stock_status', $new_post_meta)) {
                    //set to instock if _stock is > 0, otherwise set to outofstock
                    $new_post_meta['_stock_status'] = (intval($new_post_meta['_stock']) > 0) ? 'instock' : 'outofstock';
                }

            } else {

                //set _manage_stock to no if not explicitly set by CSV
                if(!array_key_exists('_manage_stock', $new_post_meta)) $new_post_meta['_manage_stock'] = 'no';
            }

            //try to find a product with a matching SKU
            $existing_product = null;
            if(array_key_exists('_sku', $new_post_meta) && !empty($new_post_meta['_sku']) > 0) {
                $existing_post_query = array(
                    'numberposts' => 1,
                    'meta_key' => '_sku',
                    'meta_query' => array(
                        array(
                            'key'=>'_sku',
                            'value'=> $new_post_meta['_sku'],
                            'compare' => '='
                        )
                    ),
                    'post_type' => 'product');
                $existing_posts = get_posts($existing_post_query);
                if(is_array($existing_posts) && sizeof($existing_posts) > 0) {
                    $existing_product = array_shift($existing_posts);
                }
            }

            if(strlen($new_post['post_title']) > 0 || $existing_product !== null) {

                //insert/update product
                if($existing_product !== null) {
                    $new_post_messages[] = sprintf( __( 'Updating product with ID %s.', 'woo-product-importer' ), $existing_product->ID );

                    $new_post['ID'] = $existing_product->ID;
                    $new_post_id = wp_update_post($new_post);
                } else {

                    //merge in default values since we're creating a new product from scratch
                    $new_post = array_merge($new_post_defaults, $new_post);
                    $new_post_meta = array_merge($new_post_meta_defaults, $new_post_meta);

                    $new_post_id = wp_insert_post($new_post, true);
                }

                if(is_wp_error($new_post_id)) {
                    $new_post_errors[] = sprintf( __( 'Couldn\'t insert product with name %s.', 'woo-product-importer' ), $new_post['post_title'] );
                } elseif($new_post_id == 0) {
                    $new_post_errors[] = sprintf( __( 'Couldn\'t update product with ID %s.', 'woo-product-importer' ), $new_post['ID'] );
                } else {
                    //insert successful!
                    $new_post_insert_success = true;

                    //set post_meta on inserted post
                    foreach($new_post_meta as $meta_key => $meta_value) {
                        add_post_meta($new_post_id, $meta_key, $meta_value, true) or
                            update_post_meta($new_post_id, $meta_key, $meta_value);
                    }

                    //set _product_attributes postmeta to the custom fields array. WP will serialize it for us.
                    //first, work on existing attributes
                    if($existing_product !== null) {
                        $existing_product_attributes = get_post_meta($new_post_id, '_product_attributes', true);
                        if(is_array($existing_product_attributes)) {
                            //set the 'position' value for all *new* attributes.
                            $max_position = 0;
                            foreach($existing_product_attributes as $field_slug => $field_data) {
                                $max_position = max(intval($field_data['position']), $max_position);
                            }
                            foreach($new_post_custom_fields as $field_slug => $field_data) {
                                if(!array_key_exists($field_slug, $existing_product_attributes)) {
                                    $new_post_custom_fields[$field_slug]['position'] = ++$max_position;
                                }
                            }
                            $new_post_custom_fields = array_merge($existing_product_attributes, $new_post_custom_fields);
                        }
                    }
                    add_post_meta($new_post_id, '_product_attributes', $new_post_custom_fields, true) or
                        update_post_meta($new_post_id, '_product_attributes', $new_post_custom_fields);

                    //set post terms on inserted post
                    foreach($new_post_terms as $tax => $term_ids) {
                        wp_set_object_terms($new_post_id, $term_ids, $tax);
                    }

                    //figure out where the uploads folder lives
                    $wp_upload_dir = wp_upload_dir();

                    //grab product images
                    foreach($new_post_image_urls as $image_index => $image_url) {

                        //convert space chars into their hex equivalent.
                        //thanks to github user 'becasual' for submitting this change
                        $image_url = str_replace(' ', '%20', trim($image_url));

                        //do some parsing on the image url so we can take a look at
                        //its file extension and file name
                        $parsed_url = parse_url($image_url);
                        $pathinfo = pathinfo($parsed_url['path']);

                        //If our 'image' file doesn't have an image file extension, skip it.
                        $allowed_extensions = array('jpg', 'jpeg', 'gif', 'png');
                        $image_ext = strtolower($pathinfo['extension']);
                        if(!in_array($image_ext, $allowed_extensions)) {
                            $new_post_errors[] = sprintf( __( 'A valid file extension wasn\'t found in %s. Extension found was %s. Allowed extensions are: %s.', 'woo-product-importer' ), $image_url, $image_ext, implode( ',', $allowed_extensions ) );
                            continue;
                        }

                        //figure out where we're putting this thing.
                        $dest_filename = wp_unique_filename( $wp_upload_dir['path'], $pathinfo['basename'] );
                        $dest_path = $wp_upload_dir['path'] . '/' . $dest_filename;
                        $dest_url = $wp_upload_dir['url'] . '/' . $dest_filename;

                        //download the image to our local server.
                        // if allow_url_fopen is enabled, we'll use that. Otherwise, we'll try cURL
                        if(ini_get('allow_url_fopen')) {
                            //attempt to copy() file show error on failure.
                            if( ! @copy($image_url, $dest_path)) {
                                $http_status = $http_response_header[0];
                                $new_post_errors[] = sprintf( __( '%s encountered while attempting to download %s', 'woo-product-importer' ), $http_status, $image_url );
                            }

                        } elseif(function_exists('curl_init')) {
                            $ch = curl_init($image_url);
                            $fp = fopen($dest_path, "wb");

                            $options = array(
                                CURLOPT_FILE => $fp,
                                CURLOPT_HEADER => 0,
                                CURLOPT_FOLLOWLOCATION => 1,
                                CURLOPT_TIMEOUT => 60); // in seconds

                            curl_setopt_array($ch, $options);
                            curl_exec($ch);
                            $http_status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
                            curl_close($ch);
                            fclose($fp);

                            //delete the file if the download was unsuccessful
                            if($http_status != 200) {
                                unlink($dest_path);
                                $new_post_errors[] = sprintf( __( 'HTTP status %s encountered while attempting to download %s', 'woo-product-importer' ), $http_status, $image_url );
                            }
                        } else {
                            //well, damn. no joy, as they say.
                            $error_messages[] = sprintf( __( 'Looks like %s is off and %s is not enabled. No images were imported.', 'woo-product-importer' ), '<code>allow_url_fopen</code>', '<code>cURL</code>'  );
                            break;
                        }

                        //make sure we actually got the file.
                        if(!file_exists($dest_path)) {
                            $new_post_errors[] = sprintf( __( 'Couldn\'t download file %s.', 'woo-product-importer' ), $image_url );
                            continue;
                        }

                        //whew. are we there yet?
                        $new_post_image_paths[] = array(
                            'path' => $dest_path,
                            'source' => $image_url
                        );
                    }

                    $image_gallery_ids = array();

                    foreach($new_post_image_paths as $image_index => $dest_path_info) {

                        //check for duplicate images, only for existing products
                        if($existing_product !== null && intval($post_data['product_image_skip_duplicates'][$key]) == 1) {
                            $existing_attachment_query = array(
                                'numberposts' => 1,
                                'meta_key' => '_import_source',
                                'post_status' => 'inherit',
                                'post_parent' => $existing_product->ID,
                                'meta_query' => array(
                                    array(
                                        'key'=>'_import_source',
                                        'value'=> $dest_path_info['source'],
                                        'compare' => '='
                                    )
                                ),
                                'post_type' => 'attachment');
                            $existing_attachments = get_posts($existing_attachment_query);
                            if(is_array($existing_attachments) && sizeof($existing_attachments) > 0) {
                                //we've already got this file.
                                $new_post_messages[] = sprintf( __( 'Skipping import of duplicate image %s.', 'woo-product-importer' ), $dest_path_info['source'] );
                                continue;
                            }
                        }

                        //make sure we actually got the file.
                        if(!file_exists($dest_path_info['path'])) {
                            $new_post_errors[] = sprintf( __( 'Couldn\'t find local file %s.', 'woo-product-importer' ), $dest_path_info['path'] );
                            continue;
                        }

                        $dest_url = str_ireplace(ABSPATH, home_url('/'), $dest_path_info['path']);
                        $path_parts = pathinfo($dest_path_info['path']);

                        //add a post of type 'attachment' so this item shows up in the WP Media Library.
                        //our imported product will be the post's parent.
                        $wp_filetype = wp_check_filetype($dest_path_info['path']);
                        $attachment = array(
                            'guid' => $dest_url,
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => preg_replace('/\.[^.]+$/', '', $path_parts['filename']),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        );
                        $attachment_id = wp_insert_attachment( $attachment, $dest_path_info['path'], $new_post_id );
                        // you must first include the image.php file
                        // for the function wp_generate_attachment_metadata() to work
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        $attach_data = wp_generate_attachment_metadata( $attachment_id, $dest_path_info['path'] );
                        wp_update_attachment_metadata( $attachment_id, $attach_data );

                        //keep track of where the attachment came from so we don't import duplicates later
                        add_post_meta($attachment_id, '_import_source', $dest_path_info['source'], true) or
                            update_post_meta($attachment_id, '_import_source', $dest_path_info['source']);

                        //set the image as featured if it is the first image in the set AND
                        //the user checked the box on the preview page.
                        if($image_index == 0 && intval($post_data['product_image_set_featured'][$key]) == 1) {
                            update_post_meta($new_post_id, '_thumbnail_id', $attachment_id);
                        } else {
                            $image_gallery_ids[] = $attachment_id;
                        }
                    }

                    if(count($image_gallery_ids) > 0) {
                        update_post_meta($new_post_id, '_product_image_gallery', implode(',', $image_gallery_ids));
                    }
                }

            } else {
                $new_post_errors[] = __( 'Skipped import of product without a name', 'woo-product-importer' );
            }

/*
            //this is returned back to the results page.
            //any fields that should show up in results should be added to this array.
            $inserted_rows[] = array(
                'row_id' => $row_id,
                'post_id' => $new_post_id ? $new_post_id : '',
                'name' => $new_post['post_title'] ? $new_post['post_title'] : '',
                'sku' => $new_post_meta['_sku'] ? $new_post_meta['_sku'] : '',
                'price' => $new_post_meta['_price'] ? $new_post_meta['_price'] : '',
                'has_errors' => (sizeof($new_post_errors) > 0),
                'errors' => $new_post_errors,
                'has_messages' => (sizeof($new_post_messages) > 0),
                'messages' => $new_post_messages,
                'success' => $new_post_insert_success               
            );
*/
        }
    }

}









function WSTimeConvert($stamp) {
    if($stamp != ''){
        $timezone = new DateTimeZone(get_option("murphysmagic1_timezone"));
        $date = new DateTime($stamp, $timezone);
        return $date->format("n/j/y g:i A");
    }
}


function WSDateConvert($stamp) {
    if($stamp != ''){
        $timezone = new DateTimeZone(get_option("murphysmagic1_timezone"));
        $date = new DateTime($stamp, $timezone);
        return $date->format("n/j/y");
    }
}

$MurphysMagic1_Wordpress_Solution = new MurphysMagic1_Wordpress_Solution();

/* Exclude Category from Shop*/

/*add_filter( 'get_terms', 'get_subcategory_terms', 10, 3 );

function get_subcategory_terms( $terms, $taxonomies, $args ) {

  $new_terms = array();

  // if a product category and on the shop page
  if ( in_array( 'product_cat', $taxonomies ) && ! is_admin() && is_shop() ) {

    foreach ( $terms as $key => $term ) {

      if ( ! in_array( $term->slug, array( 'discontinued' ) ) ) {
        $new_terms[] = $term;
      }

    }

    $terms = $new_terms;
  }

  return $terms;
}*/


/*add_action('pre_get_posts', 'custom_pre_get_posts_query');
function custom_pre_get_posts_query( $q ) {

	if ( ! $q->is_main_query() ) return;
	if ( ! $q->is_post_type_archive() ) return;
	
	if ( ! is_admin() && is_shop() ) {
	
		$q->set( 'tax_query', array(array(
			'taxonomy' => 'product_cat',
			'field' => 'slug',
			'terms' => array( 'discontinued' ), // Don't display products in the discontinued category on the shop page
			'operator' => 'NOT IN'
		)));
	
	}

	remove_action('pre_get_posts', 'custom_pre_get_posts_query');
}
*/

