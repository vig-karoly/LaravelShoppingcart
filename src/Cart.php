<?php

namespace Gloudemans\Shoppingcart;

use App\Facades\CountryFacade;
use App\Models\Currency;
use Carbon\Carbon;
use Closure;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Contracts\InstanceIdentifier;
use Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Gloudemans\Shoppingcart\Traits\HasRelationsTrait;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;

class Cart
{
    use Macroable;
    use HasRelationsTrait;

    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    private $session;

    /**
     * Instance of the event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * Holds the current cart instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Holds the creation date of the cart.
     *
     * @var mixed
     */
    private $createdAt;

    /**
     * Holds the update date of the cart.
     *
     * @var mixed
     */
    private $updatedAt;

    /**
     * Defines the discount percentage.
     *
     * @var float
     */
    private $discount = 0;

    /**
     * Defines the tax rate.
     *
     * @var float
     */
    private $taxRate = 0;

    /**
     * Current content.
     *
     * @var Collection
     */
    private $content;
    private Currency $currency;

    /**
     * Cart constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $currentCountry = CountryFacade::getCurrentCountry();
        $this->session = $session;
        $this->events = $events;
        $this->taxRate = $currentCountry->tax;
        $this->currency = $currentCountry->currency;
        $this->content = new Collection();
        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current cart instance.
     *
     * @param string|null $instance
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        if ($instance instanceof InstanceIdentifier) {
            $this->discount = $instance->getInstanceGlobalDiscount();
            $instance = $instance->getInstanceIdentifier();
        }

        $this->instance = 'cart.'.$instance;

        return $this;
    }

    /**
     * Get the current cart instance.
     *
     * @return string
     */
    public function currentInstance()
    {
        return str_replace('cart.', '', $this->instance);
    }

    /**
     * Add an item to the cart.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function add($id, $name = null, $qty = null, $price = null, array $options = [])
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        $cartItem = $this->createCartItem($id, $name, $qty, $price, $options);

        return $this->addCartItem($cartItem);
    }

    /**
     * Add an item to the cart.
     *
     * @param \Gloudemans\Shoppingcart\CartItem $item          Item to add to the Cart
     * @param bool                              $keepDiscount  Keep the discount rate of the Item
     * @param bool                              $keepTax       Keep the Tax rate of the Item
     * @param bool                              $dispatchEvent
     *
     * @return \Gloudemans\Shoppingcart\CartItem The CartItem
     */
    public function addCartItem($item, $keepDiscount = false, $keepTax = false, $dispatchEvent = true)
    {
        if (!$keepDiscount) {
            $item->setDiscountRate($this->discount);
        }

        if (!$keepTax) {
            $item->setTaxRate($this->taxRate);
        }

        $content = $this->getContent();

        if ($content->has($item->rowId)) {
            $item->qty += $content->get($item->rowId)->qty;
        }

        $content->put($item->rowId, $item);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.adding', $item);
        }

        $this->setContent($content);
        // $this->session->put($this->instance, $content);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.added', $item);
        }

        return $item;
    }

    /**
     * Update the cart item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function update($rowId, $qty)
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            $itemOldIndex = $content->keys()->search($rowId);

            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);

            return;
        } else {
            if (isset($itemOldIndex)) {
                $content = $content->slice(0, $itemOldIndex)
                    ->merge([$cartItem->rowId => $cartItem])
                    ->merge($content->slice($itemOldIndex));
            } else {
                $content->put($cartItem->rowId, $cartItem);
            }
        }

        $this->events->dispatch('cart.updating', $cartItem);

        $this->setContent($content);
        // $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.updated', $cartItem);

        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param string $rowId
     *
     * @return void
     */
    public function remove($rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->events->dispatch('cart.removing', $cartItem);

        $this->setContent($content);
        // $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.removed', $cartItem);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if (!$content->has($rowId)) {
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        }

        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy()
    {
        $this->content->forget($this->currentInstance());
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the cart.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content()
    {
        if (is_null($this->session->get($this->instance))) {
            return new Collection([]);
        }

        return $this->session->get($this->instance);
    }

    /**
     * Get the content of the cart. Save the cart when identifier is provided.
     *
     * @param \Closure $callback
     * @param mixed    $identifier
     *
     * @return \Illuminate\Support\Collection
     */
    public function contentWithRelations(Closure $callback, $identifier = null)
    {
        $relations = [];
        $content = $this->getContent();
        $count = $content->count();
        $ids = $content->pluck('id');

        $items = null;
        if ($ids->count() > 0) {
            $items = $callback($ids->all());
        }
        $updatedContent = new Collection([]);

        if ($items) {
            $content->map(function (CartItem $cartItem) use ($items, &$relations, $updatedContent) {
                $item = $items->firstWhere('id', $cartItem->id);
                if ($item) {
                    $newCartItem = CartItem::fromBuyable($item, $cartItem->options->toArray());
                    $newCartItem->setDiscountRate($cartItem->getDiscountRate());

                    $newCartItem->setSubtotal(($newCartItem->price - $newCartItem->discount) / (1 + $this->taxRate/100) );

                    $newCartItem->setQuantity($cartItem->qty ? $cartItem->qty : 1);

                    $relations[$newCartItem->rowId] = $item;
                    $updatedContent->put($newCartItem->rowId, $newCartItem);

                    return $newCartItem;
                }

                if (array_key_exists($cartItem->rowId, $relations)) {
                    unset($relations[$cartItem->rowId]);
                }

                $cartItem->setQuantity(0);

                return $cartItem;
            })->reject(function (CartItem $cartItem) {
                return $cartItem->qty <= 0;
            });

            // Set relations.
            $this->setRelations($relations);

            // Update cart content.
            $this->setContent($updatedContent);
        }

        // Save the cart to database.
        if (!is_null($identifier)) {
            $this->store($identifier);
        }

        return $updatedContent;
    }

    /**
     * Set the content of the cart.
     *
     * @param \Illuminate\Support\Collection content
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function setContent(Collection $content)
    {
        $this->content->put($this->currentInstance(), $content);
        $this->session->put($this->instance, $content);

        return $this;
    }

    /**
     * Get the carts content.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getCurrentContent()
    {
        return $this->content;
    }

    /**
     * Get the total quantity of all CartItems in the cart.
     *
     * @return int|float
     */
    public function count()
    {
        return $this->getContent()->sum('qty');
    }

    /**
     * Get the amount of CartItems in the Cart.
     * Keep in mind that this does NOT count quantity.
     *
     * @return int|float
     */
    public function countItems()
    {
        return $this->getContent()->count();
    }

    /**
     * Get the total price of the items in the cart.
     *
     * @return float
     */
    public function totalFloat()
    {
        return $this->getContent()->reduce(function ($total, CartItem $cartItem) {
            return $total + $cartItem->total;
        }, 0);
    }

    /**
     * Get the total price of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     *
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->totalFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function totalWithCurrency() {
        return $this->currencyFormat($this->total());
    }

    /**
     * Get the total tax of the items in the cart.
     *
     * @return float
     */
    public function taxFloat()
    {
        return $this->getContent()->reduce(function ($tax, CartItem $cartItem) {
            return $tax + $cartItem->taxTotal;
        }, 0);
    }

    /**
     * Get the total tax of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     *
     * @return string
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->taxFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function taxWithCurrency() {
        return $this->currencyFormat($this->tax());
    }
    /**
     * Get the subtotal (total - tax) of the items in the cart.
     *
     * @return float
     */
    public function subtotalFloat()
    {
        return $this->getContent()->reduce(function ($subTotal, CartItem $cartItem) {
            return $subTotal + $cartItem->subtotal;
        }, 0);
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     *
     * @return string
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->subtotalFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function subTotalWithCurrency() {
        return $this->currencyFormat($this->subtotal());
    }

    /**
     * Get the discount of the items in the cart.
     *
     * @return float
     */
    public function discountFloat()
    {
        return $this->getContent()->reduce(function ($discount, CartItem $cartItem) {
            return $discount + $cartItem->discountTotal;
        }, 0);
    }

    /**
     * Get the discount of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     *
     * @return string
     */
    public function discount($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->discountFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function discountWithCurrency() {
        return $this->currencyFormat($this->discount());
    }

    /**
     * Get the price of the items in the cart (not rounded).
     *
     * @return float
     */
    public function initialFloat()
    {
        return $this->getContent()->reduce(function ($initial, CartItem $cartItem) {
            return $initial + ($cartItem->qty * $cartItem->price);
        }, 0);
    }

    /**
     * Get the price of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     *
     * @return string
     */
    public function initial($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->initialFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function initialWithCurrency() {
        return $this->currencyFormat($this->initial());
    }
    /**
     * Get the price of the items in the cart (previously rounded).
     *
     * @return float
     */
    public function priceTotalFloat()
    {
        return $this->getContent()->reduce(function ($initial, CartItem $cartItem) {
            return $initial + $cartItem->priceTotal;
        }, 0);
    }

    /**
     * Get the price of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     *
     * @return string
     */
    public function priceTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTotalFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function priceTotalDiscounted($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTotalFloat()-$this->discountFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function priceTotalDiscountedWithCurrency() {
        return $this->currencyFormat($this->priceTotalDiscounted());
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     *
     * @param \Closure $search
     *
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search)
    {
        return $this->getContent()->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     *
     * @return void
     */
    public function associate($rowId, $model)
    {
        if (is_string($model) && !class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->setContent($content);
        // $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     *
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $cartItem = $this->get($rowId);

        $cartItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->setContent($content);
        // $this->session->put($this->instance, $content);
    }

    /**
     * Set the global tax rate for the cart.
     * This will set the tax rate for all items.
     *
     * @param float $discount
     */
    public function setGlobalTax($taxRate)
    {
        $this->taxRate = $taxRate;

        $content = $this->getContent();
        if ($content && $content->count()) {
            $content->each(function ($item, $key) {
                $item->setTaxRate($this->taxRate);
            });
        }
    }

    /**
     * Set the discount rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     *
     * @return void
     */
    public function setDiscount($rowId, $discount)
    {
        $cartItem = $this->get($rowId);

        $cartItem->setDiscountRate($discount);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->setContent($content);
        // $this->session->put($this->instance, $content);
    }

    /**
     * Set the global discount percentage for the cart.
     * This will set the discount for all cart items.
     *
     * @param float $discount
     *
     * @return void
     */
    public function setGlobalDiscount($discount)
    {
        $this->discount = $discount;

        $content = $this->getContent();
        if ($content && $content->count()) {
            $content->each(function ($item, $key) {
                $item->setDiscountRate($this->discount);
            });
        }
    }

    /**
     * Store the current instance of the cart in the database.
     *
     * @param mixed $identifier
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function store($identifier)
    {
        $content = $this->getContent();

        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $this->getConnection()->table($this->getTableName())->updateOrInsert(
            [
                'identifier' => $identifier,
                'instance'   => $this->currentInstance(),
            ],
            [
                'content'    => serialize($content),
                'created_at' => $this->createdAt ?: Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        $this->events->dispatch('cart.store');

        return $this;
    }

    /**
     * Restore the saved cart with the given identifier.
     *
     * @param mixed $identifier
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function restore($identifier)
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $currentInstance = $this->currentInstance();

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier'=> $identifier, 'instance' => $currentInstance])->first();

        if (!$stored) {
            return $this;
        }

        $storedContent = unserialize(data_get($stored, 'content'));

        $this->setContent($storedContent);

        $this->createdAt = Carbon::parse(data_get($stored, 'created_at'));
        $this->updatedAt = Carbon::parse(data_get($stored, 'updated_at'));

        $this->events->dispatch('cart.restore');

        return $this;
    }

    /**
     * Erase the cart with the given identifier.
     *
     * @param mixed $identifier
     *
     * @return void
     */
    public function erase($identifier)
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $instance = $this->currentInstance();

        if (!$this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            return;
        }

        $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $instance])->delete();

        $this->events->dispatch('cart.erased');
    }

    /**
     * Delete and Erase the cart with the given identifier.
     *
     * @param mixed $identifier
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function destroyAndDelete($identifier)
    {
        $this->destroy();

        if (!is_null($identifier)) {
            if ($identifier instanceof InstanceIdentifier) {
                $identifier = $identifier->getInstanceIdentifier();
            }

            // Do not need to check if exist to delete. Avoid 1 query to check if exists.
            $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $this->currentInstance()])->delete();

            $this->events->dispatch('cart.deleted');
        }

        return $this;
    }

    /**
     * Merges the contents of another cart into this cart.
     *
     * @param mixed $identifier   Identifier of the Cart to merge with.
     * @param bool  $keepDiscount Keep the discount of the CartItems.
     * @param bool  $keepTax      Keep the tax of the CartItems.
     * @param bool  $dispatchAdd  Flag to dispatch the add events.
     *
     * @return bool
     */
    public function merge($identifier, $keepDiscount = false, $keepTax = false, $dispatchAdd = true, $instance = self::DEFAULT_INSTANCE)
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        if (!$this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            return false;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier'=> $identifier, 'instance'=> $instance])->first();

        $storedContent = unserialize($stored->content);

        foreach ($storedContent as $cartItem) {
            $this->addCartItem($cartItem, $keepDiscount, $keepTax, $dispatchAdd);
        }

        $this->events->dispatch('cart.merged');

        return true;
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     *
     * @return float|null
     */
    public function __get($attribute)
    {
        switch ($attribute) {
            case 'total':
                return $this->total();
            case 'tax':
                return $this->tax();
            case 'subtotal':
                return $this->subtotal();
            case 'discount':
                return $this->discount();
            default:
                return;
        }
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent()
    {
        if ($this->content->has($this->currentInstance())) {
            return $this->content->get($this->currentInstance());
        }

        if ($this->session->has($this->instance)) {
            return $this->session->get($this->instance);
        }

        return new Collection();
    }

    /**
     * Create a new CartItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param array     $options
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    private function createCartItem($id, $name, $qty, $price, array $options)
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
            $this->setRelation($cartItem->rowId, $id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $options);
            $cartItem->setQuantity($qty);
        }

        $cartItem->setInstance($this->currentInstance());

        return $cartItem;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     *
     * @return bool
     */
    private function isMulti($item)
    {
        if (!is_array($item)) {
            return false;
        }

        return is_array(head($item)) || head($item) instanceof Buyable;
    }

    /**
     * @param $identifier
     *
     * @return bool
     */
    private function storedCartInstanceWithIdentifierExists($instance, $identifier)
    {
        return $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance'=> $instance])->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    private function getConnection()
    {
        return app(DatabaseManager::class)->connection($this->getConnectionName());
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    private function getTableName()
    {
        return config('cart.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    /**
     * Get the Formatted number.
     *
     * @param $value
     * @param $decimals
     * @param $decimalPoint
     * @param $thousandSeperator
     *
     * @return string
     */
    private function numberFormat($value, $decimals, $decimalPoint, $thousandSeperator)
    {
        if (is_null($decimals)) {
            $decimals = $this->currency->decimals;
        }

        if (is_null($decimalPoint)) {
            $decimalPoint = config('cart.format.decimal_point', '.');
        }

        if (is_null($thousandSeperator)) {
            $thousandSeperator = config('cart.format.thousand_separator', ',');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }

    private function currencyFormat($value)
    {
        if($this->currency->place === 'before') {
            return $this->currency->symbol . ' ' . $value;
        } else {
            return $value . ' ' . $this->currency->symbol;
        }
    }

    /**
     * Get the creation date of the cart (db context).
     *
     * @return \Carbon\Carbon|null
     */
    public function createdAt()
    {
        return $this->createdAt;
    }

    /**
     * Get the lats update date of the cart (db context).
     *
     * @return \Carbon\Carbon|null
     */
    public function updatedAt()
    {
        return $this->updatedAt;
    }
}
