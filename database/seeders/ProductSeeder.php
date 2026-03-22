<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            ['name' => 'Wireless Earbuds',        'description' => 'True wireless earbuds with active noise cancellation and 24h battery life.',    'price' => 49.99],
            ['name' => 'USB-C Hub',               'description' => '7-in-1 USB-C hub with HDMI 4K, 3x USB-A, SD card reader, and 100W PD charging.', 'price' => 34.99],
            ['name' => 'Mechanical Keyboard',     'description' => 'Compact TKL mechanical keyboard with tactile brown switches and RGB backlight.',  'price' => 79.99],
            ['name' => 'Webcam 1080p',            'description' => 'Full HD webcam with built-in microphone and auto light correction.',              'price' => 44.99],
            ['name' => 'Phone Stand',             'description' => 'Adjustable aluminum desk stand for phones and small tablets.',                   'price' =>  9.99],
            ['name' => 'Braided USB-C Cable',     'description' => '2m fast-charge braided USB-C to USB-C cable, 60W rated.',                        'price' =>  7.99],
            ['name' => 'Wireless Mouse',          'description' => 'Ergonomic wireless mouse with 3 DPI settings and silent click.',                  'price' => 24.99],
            ['name' => 'Laptop Sleeve 15"',       'description' => 'Water-resistant neoprene sleeve fits laptops up to 15.6 inches.',                 'price' => 14.99],
            ['name' => 'LED Desk Lamp',           'description' => 'Touch-control LED desk lamp with 5 colour temps and USB charging port.',          'price' => 29.99],
            ['name' => 'Portable Charger 20K',    'description' => '20,000 mAh power bank with dual USB-A and one USB-C output.',                    'price' => 39.99],
            ['name' => 'Screen Cleaning Kit',     'description' => 'Microfibre cloth + 100ml spray solution for monitors and phone screens.',         'price' =>  4.99],
            ['name' => 'Bluetooth Speaker',       'description' => 'Compact IPX6 waterproof speaker with 12h playback and built-in mic.',             'price' => 54.99],
            ['name' => 'Cable Management Box',    'description' => 'Large cable organiser box hides power strips and messy cables.',                  'price' => 19.99],
            ['name' => 'Smart Plug',              'description' => 'Wi-Fi smart plug with energy monitoring, works with Alexa and Google Home.',      'price' => 12.99],
            ['name' => 'Ergonomic Wrist Rest',    'description' => 'Memory-foam wrist rest pad for keyboard and mouse, non-slip base.',               'price' =>  9.99],
        ];

        foreach ($products as $product) {
            \App\Models\Product::create($product);
        }
    }
}
