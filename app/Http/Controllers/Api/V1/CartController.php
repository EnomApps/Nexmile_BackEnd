<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Services\Orders\CartService;
use App\Services\Orders\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cart and checkout (EP5).
 *
 * Authenticated but not role-gated: a rider ordering dinner is a customer here
 * — see docs/ROLES.md.
 */
class CartController extends Controller
{
    public function __construct(
        protected CartService $carts,
        protected CheckoutService $checkout,
    ) {}

    /**
     * List open carts
     *
     * One per restaurant. Glancing at another shop does not empty the basket
     * you already started.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CartResource::collection($this->carts->all($request->user())),
        ]);
    }

    /**
     * Show one restaurant's cart
     */
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $cart = $this->cart($request, $restaurant);

        return response()->json(['data' => new CartResource($cart)]);
    }

    /**
     * Add an item to the cart
     */
    public function store(Request $request, int $restaurant): JsonResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'option_ids' => ['sometimes', 'array'],
            'option_ids.*' => ['integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $cart = $this->cart($request, $restaurant);

        $this->carts->add(
            $cart,
            $data['menu_item_id'],
            $data['quantity'] ?? 1,
            $data['option_ids'] ?? [],
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => 'Added to your cart.',
            'data' => new CartResource($cart->fresh()),
        ], 201);
    }

    /**
     * Change a line's quantity
     *
     * A quantity of 0 removes the line, so the app's minus button does not
     * need a second endpoint at the boundary.
     */
    public function updateItem(Request $request, int $restaurant, int $item): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $cart = $this->cart($request, $restaurant);

        $this->carts->setQuantity($cart, $this->line($cart, $item), $data['quantity']);

        return response()->json([
            'message' => 'Cart updated.',
            'data' => new CartResource($cart->fresh()),
        ]);
    }

    /**
     * Remove a line
     */
    public function destroyItem(Request $request, int $restaurant, int $item): JsonResponse
    {
        $cart = $this->cart($request, $restaurant);

        $this->carts->remove($cart, $this->line($cart, $item));

        return response()->json([
            'message' => 'Removed from your cart.',
            'data' => new CartResource($cart->fresh()),
        ]);
    }

    /**
     * Empty the cart
     */
    public function clear(Request $request, int $restaurant): JsonResponse
    {
        $this->carts->clear($this->cart($request, $restaurant));

        return response()->json(['message' => 'Cart emptied.']);
    }

    /**
     * Place the order
     *
     * Totals are never accepted from the client. Everything that could have
     * changed while the customer was shopping — prices, availability, opening
     * hours, the delivery radius — is re-checked here.
     */
    public function checkout(Request $request, int $restaurant): JsonResponse
    {
        $data = $request->validate([
            'fulfilment_type' => ['required', 'in:'.implode(',', FulfilmentType::values())],
            'payment_method' => ['required', 'in:'.implode(',', PaymentMethod::values())],
            'address_id' => ['required_if:fulfilment_type,delivery', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'address_id.required_if' => 'Choose a delivery address.',
        ]);

        $fulfilment = FulfilmentType::from($data['fulfilment_type']);

        $address = $fulfilment === FulfilmentType::Delivery
            // Scoped to the caller: another customer's address id is a 404,
            // not a way to have food sent to a stranger.
            ? Address::where('user_id', $request->user()->id)->findOrFail($data['address_id'])
            : null;

        $order = $this->checkout->place(
            $request->user(),
            $this->cart($request, $restaurant),
            $fulfilment,
            PaymentMethod::from($data['payment_method']),
            $address,
            $data['note'] ?? null,
        );

        return response()->json([
            'message' => 'Order placed.',
            'data' => new OrderResource($order),
        ], 201);
    }

    /**
     * A cart only exists for a restaurant a customer could actually order
     * from, so an unverified merchant id is a 404 rather than an empty cart.
     */
    protected function cart(Request $request, int $restaurant): Cart
    {
        $merchant = Merchant::where('kyc_status', KycStatus::Verified->value)->findOrFail($restaurant);

        return $this->carts->forMerchant($request->user(), $merchant->id)
            ->load(['merchant', 'items.menuItem', 'items.options.itemOption.group']);
    }

    protected function line(Cart $cart, int $item): CartItem
    {
        return $cart->items()->findOrFail($item);
    }
}
