<?php
/**
* ProductDevAPITests - tests for the product dev api
*/
class ProductDevAPITests extends ShoppTestCase
{

	function test_add_product () {
		$data = array(
			'name' => "St. John's Bay® Color Block Windbreaker",
			'publish' => array( 'flag' => false,
								'publishtime' => array('month' => 12,
								'day' => 25,
								'year' => 2011,
								'hour' => 0,
								'minute' => 0,
								'meridian' => 'AM')
			 					),
			'description' => "This water-repellent windbreaker offers lightweight protection on those gusty days.

			hood with drawstring
			zip front
			2 inner pockets
			on-seam pockets
			contrast side panels
			elastic cuffs
			side elastic on bottom
			contrast mesh lining
			polyester microfiber
			polyester mesh lining
			washable
			imported",
			'summary' => "This water-repellent windbreaker offers lightweight protection on those gusty days.",
			'featured' => true,
			'categories'=> array('terms' => array(5)),
			'tags'=>array('terms'=>array('action')),
			'specs'=>array('pockets'=>2, 'drawstring'=>'yes','washable'=>'yes'),
			'variants'=>array(
				'menu' => array(
					'Size' => array('medium','large','x-large','small','xx-large','large-tall','x-large tall','2x-large tall','2x-large'),
					'Color' => array('Black/Grey Colorbi', 'Navy Baby Solid','Red/Iron Colorbloc','Iron Solid','Dark Avocado Soil')
				)
			),
			'addons'=> array(
				'menu' => array('Special' => array('Embroidered'))
			),
			'packaging' => true
			// 'processing' => array( 'flag' => true, 'min' => array('interval'=>3,'period'=>'d'), 'max' => array('interval'=>5,'period'=>'d'))  // order processing adds from 3 to 5 days. (not implemented yet)
		);

		$data['variants'][] = array(
			'option' => array('Size'=>'medium', 'Color' => 'Navy Baby Solid'),
			'type' => 'Shipped',
			'price' => 40.00,
			'sale' => array('flag'=>true, 'price' => 19.99),
			'shipping' => array('flag'=>true, 'fee'=>1.50, 'weight'=>1.1, 'length'=>10.0, 'width'=>10.0, 'height'=>2.0),
			'inventory'=>array('flag'=>true, 'stock'=>10, 'sku'=>'WINDBREAKER1')
		);
		$data['addons'][] = array(
			'option' => array('Special'=>'Embroidered'),
			'type' => 'Shipped',
			'price' => 10.00
		);

		$Product = shopp_add_product($data);

		// Load fresh for testing
		$Product = new Product(130);
		$Product->load_data();

		$this->AssertEquals(130, $Product->id);
		$this->AssertEquals('St. John\'s Bay® Color Block Windbreaker',$Product->name);
		$this->AssertEquals('This water-repellent windbreaker offers lightweight protection on those gusty days.',$Product->summary);
		$this->AssertEquals('on', $Product->featured);
		$this->AssertEquals('on', $Product->sale);
		$this->AssertEquals(40.0, $Product->maxprice);
		$this->AssertEquals(0.0, $Product->minprice);
		$this->AssertEquals('on', $Product->packaging);
		$this->AssertEquals('a:2:{s:1:"v";a:2:{i:1;a:3:{s:2:"id";i:1;s:4:"name";s:4:"Size";s:7:"options";a:9:{i:1;a:3:{s:2:"id";i:1;s:4:"name";s:6:"medium";s:6:"linked";s:3:"off";}i:2;a:3:{s:2:"id";i:2;s:4:"name";s:5:"large";s:6:"linked";s:3:"off";}i:3;a:3:{s:2:"id";i:3;s:4:"name";s:7:"x-large";s:6:"linked";s:3:"off";}i:4;a:3:{s:2:"id";i:4;s:4:"name";s:5:"small";s:6:"linked";s:3:"off";}i:5;a:3:{s:2:"id";i:5;s:4:"name";s:8:"xx-large";s:6:"linked";s:3:"off";}i:6;a:3:{s:2:"id";i:6;s:4:"name";s:10:"large-tall";s:6:"linked";s:3:"off";}i:7;a:3:{s:2:"id";i:7;s:4:"name";s:12:"x-large tall";s:6:"linked";s:3:"off";}i:8;a:3:{s:2:"id";i:8;s:4:"name";s:13:"2x-large tall";s:6:"linked";s:3:"off";}i:9;a:3:{s:2:"id";i:9;s:4:"name";s:8:"2x-large";s:6:"linked";s:3:"off";}}}i:2;a:3:{s:2:"id";i:2;s:4:"name";s:5:"Color";s:7:"options";a:5:{i:10;a:3:{s:2:"id";i:10;s:4:"name";s:18:"Black/Grey Colorbi";s:6:"linked";s:3:"off";}i:11;a:3:{s:2:"id";i:11;s:4:"name";s:15:"Navy Baby Solid";s:6:"linked";s:3:"off";}i:12;a:3:{s:2:"id";i:12;s:4:"name";s:18:"Red/Iron Colorbloc";s:6:"linked";s:3:"off";}i:13;a:3:{s:2:"id";i:13;s:4:"name";s:10:"Iron Solid";s:6:"linked";s:3:"off";}i:14;a:3:{s:2:"id";i:14;s:4:"name";s:17:"Dark Avocado Soil";s:6:"linked";s:3:"off";}}}}s:1:"a";a:1:{i:1;a:3:{s:2:"id";i:1;s:4:"name";s:7:"Special";s:7:"options";a:1:{i:1;a:3:{s:2:"id";i:1;s:4:"name";s:11:"Embroidered";s:6:"linked";s:3:"off";}}}}}',
							serialize($Product->options));
		$this->AssertEquals(46, count($Product->prices));

		$counts = array('product'=>0,'addon'=>0,'variation'=>0);
		$Variant = $Addon = false;
		foreach ( $Product->prices as $index => $Price ) {
			$counts[$Price->context]++;
			if ( 7001 == $Price->optionkey ) $Addon = &$Product->prices[$index];
			if ( 79754 == $Price->optionkey ) $Variant = &$Product->prices[$index];
		}

		$this->AssertEquals(45, $counts['variation']);
		$this->AssertEquals(1, $counts['addon']);
		$this->AssertEquals(0, $counts['product']);

		// Variant assertions
		$this->AssertEquals('1,11',$Variant->options);
		$this->AssertEquals('medium, Navy Baby Solid', $Variant->label);
		$this->AssertEquals('Shipped', $Variant->type);
		$this->AssertEquals('variation', $Variant->context);
		$this->AssertEquals('on', $Variant->sale);
		$this->AssertEquals(40.00, $Variant->price);
		$this->AssertEquals(19.99, $Variant->promoprice);
		$this->AssertEquals(19.99, $Variant->saleprice);
		$this->AssertEquals('on', $Variant->tax);
		$this->AssertEquals('on', $Variant->shipping);
		$this->AssertEquals('a:4:{s:6:"weight";d:1.1000000000000001;s:6:"height";d:2;s:5:"width";d:10;s:6:"length";d:10;}',serialize($Variant->dimensions));
		$this->AssertEquals(1.5, $Variant->shipfee);
		$this->AssertEquals('on', $Variant->inventory);
		$this->AssertEquals(10, $Variant->stock);
		$this->AssertEquals(10, $Variant->stocked);
		$this->AssertEquals('WINDBREAKER1', $Variant->sku);

		$this->AssertEquals('1',$Addon->options);
		$this->AssertEquals('Embroidered', $Addon->label);
		$this->AssertEquals('Shipped', $Addon->type);
		$this->AssertEquals('addon', $Addon->context);
		$this->AssertEquals('off', $Addon->sale);
		$this->AssertEquals(10, $Addon->price);
		$this->AssertEquals('on', $Addon->tax);
		$this->AssertEquals('on', $Addon->shipping);
		$this->AssertEquals(0, $Addon->shipfee);
		$this->AssertEquals('off', $Addon->inventory);
	}

}
?>