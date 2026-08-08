<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Tax invoices (EP11). A GST-registered restaurant has to be able to produce
 * one, and every figure is read off the order rather than recomputed.
 */
class InvoiceTest extends CheckoutTest
{
    /** @return array{Merchant, array<string, mixed>, User} */
    private function placedOrder(): array
    {
        $shop = $this->restaurant(['packaging_fee' => 10]);
        $shop->forceFill(['gstin' => '33AAAAA0000A1Z5'])->save();

        Sanctum::actingAs($customer = $this->customer());
        $address = $customer->addresses()->create([
            'label' => 'home', 'contact_name' => 'Meena', 'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar', 'city' => 'Madurai', 'pincode' => '625020',
            'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $this->dish($shop)->id, 'quantity' => 2,
        ])->assertCreated();

        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        return [$shop, $order, $customer];
    }

    public function test_the_invoice_shows_the_seller_buyer_and_totals(): void
    {
        [$shop, $order] = $this->placedOrder();

        $page = $this->actingAs($shop->user)
            ->get("/merchants/orders/{$order['id']}/invoice")
            ->assertOk();

        $page->assertSee('Tax invoice')
            ->assertSee('INV-'.$order['order_number'])
            ->assertSee('33AAAAA0000A1Z5')
            ->assertSee('Ponnusamy Hotel')
            ->assertSee('Meena')
            // 400 items + 10 packaging + 0 delivery (free above 299) + 20 tax
            ->assertSee('430.00');
    }

    public function test_gst_is_split_into_cgst_and_sgst(): void
    {
        [$shop, $order] = $this->placedOrder();

        // Nexmile delivers within 1 km, so supply is always intra-state and
        // the tax divides equally rather than being charged as IGST.
        $this->actingAs($shop->user)
            ->get("/merchants/orders/{$order['id']}/invoice")
            ->assertOk()
            ->assertSee('CGST')
            ->assertSee('SGST')
            ->assertSee('10.00');   // half of the 20.00 tax on a 5% line
    }

    public function test_the_customer_can_fetch_their_own_invoice(): void
    {
        [, $order, $customer] = $this->placedOrder();

        Sanctum::actingAs($customer);

        $this->get("/api/v1/orders/{$order['id']}/invoice")
            ->assertOk()
            ->assertSee('INV-'.$order['order_number']);
    }

    public function test_an_invoice_belonging_to_someone_else_is_not_reachable(): void
    {
        [$shop, $order] = $this->placedOrder();

        Sanctum::actingAs($this->customer());
        $this->get("/api/v1/orders/{$order['id']}/invoice")->assertNotFound();

        $this->actingAs($this->restaurant()->user)
            ->get("/merchants/orders/{$order['id']}/invoice")
            ->assertNotFound();
    }

    public function test_the_invoice_does_not_move_when_the_menu_does(): void
    {
        [$shop, $order] = $this->placedOrder();

        // The order snapshotted its prices; an invoice that drifts from what
        // the customer paid is worse than no invoice.
        $shop->menuItems()->first()->update(['name' => 'Something Else', 'price' => 900]);

        $this->actingAs($shop->user)
            ->get("/merchants/orders/{$order['id']}/invoice")
            ->assertOk()
            ->assertSee('Chicken Biryani')
            ->assertDontSee('Something Else');
    }

    public function test_a_delivery_fee_appears_as_its_own_tax_line(): void
    {
        $shop = $this->restaurant();

        Sanctum::actingAs($customer = $this->customer());
        $address = $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);

        // A small basket pays delivery, which is a service at 18% rather than
        // food at 5% — folding them together would misstate both.
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $this->dish($shop, ['price' => 60])->id,
        ])->assertCreated();

        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        $this->actingAs($shop->user)
            ->get("/merchants/orders/{$order['id']}/invoice")
            ->assertOk()
            ->assertSee('delivery')
            ->assertSee('18%');

        $this->assertMoney(92.5, Order::sole()->grand_total);
    }
}
