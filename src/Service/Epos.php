<?php

namespace ProApps\Bundle\AppBundle\Service\Rest;

use Doctrine\ORM\EntityManager;
use ProApps\Bundle\AppBundle\Entity\Order;
use ProApps\Bundle\AppBundle\Entity\Product;
use ProApps\Bundle\AppBundle\Entity\User;

class Epos
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var string
     */
    private $token;

    const LOG_FILE_PATH = __DIR__.'/../../../../../app/logs/eposnow.log';

    const LOCATION_JM_ID = 14340;

    const TENDER_ID_CARD = 1534;
    const TENDER_ID_PAYPAL = 25245;

    const TRANSACTION_ENDPOINT = 'https://api.eposnowhq.com/api/V2/Transaction/';
    const TRANSACTION_FULL_ENDPOINT = 'https://api.eposnowhq.com/api/V2/CompleteTransaction/';
    const CUSTOMER_ENDPOINT = 'https://api.eposnowhq.com/api/V2/Customer/';
    const CUSTOMER_ADDRESS_ENDPOINT = 'https://api.eposnowhq.com/api/V2/CustomerAddress/';
    const STOCK_ENDPOINT = 'https://api.eposnowhq.com/api/V2/ProductStock/';

    /**
     * Epos constructor.
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
        $this->token = $em->getRepository('ProAppsPanelBundle:Config')->findByKey('EPOS_NOW');
    }

    /**
     * #outgoing
     *
     * Add new user to ePOS NOW database
     *
     * @param User $user
     */
    public function createNewUser(User $user)
    {
        if($user->getPosID()) {
            if($this->isUserRegistered($user)) return;
        }

        $customer = $this->req('POST', self::CUSTOMER_ENDPOINT, [
            'Forename' => $user->getPublicName(),
            'MaxCredit' => 0,
            'SignUpDate' => $user->getCreatedTime()->format('Y-m-d H:i:s'),
            'EmailAddress' => $user->getUsername(),
            'ContactNumber' => $user->__get('phone')
        ]);
        if($customer === null) return;

        $user->setPosID($customer['CustomerID']);
        $this->em->persist($user);
        $this->em->flush();

        if($user->__get('city')) $this->createNewAddress($user);
    }

    /**
     * #outgoing
     *
     * Update user in ePOS NOW database
     *
     * @param User $user
     */
    public function updateUser(User $user)
    {
        $this->req('PUT', self::CUSTOMER_ENDPOINT.$user->getPosID(), [
            'Forename' => $user->getPublicName(),
            'EmailAddress' => $user->getUsername(),
            'ContactNumber' => str_replace('+', '', $user->__get('phone'))
        ]);
    }

    /**
     * #outgoing
     *
     * Remove user from ePOS NOW database
     *
     * @param User $user
     */
    public function removeUser(User $user)
    {
        $this->req('DELETE', self::CUSTOMER_ENDPOINT.$user->getPosID());
    }

    /**
     * #outgoing
     *
     * Check user is registered in ePOS NOW database
     *
     * @param User $user
     * @return bool
     */
    public function isUserRegistered(User $user)
    {
        $existCheck = $this->req('GET', self::CUSTOMER_ENDPOINT.$user->getPosID());
        return isset($existCheck['CustomerID']);
    }

    /**
     * #outgoing
     *
     * Check user has main address in ePOS NOW database
     *
     * @param User $user
     * @return bool
     */
    public function hasUserAddress(User $user)
    {
        $existCheck = $this->req('GET', self::CUSTOMER_ENDPOINT.$user->getPosID());
        return (bool)$existCheck['MainAddressID'];
    }

    /**
     * #outgoing
     *
     * Add new user's address to ePOS NOW database
     *
     * @param User $user
     */
    public function createNewAddress(User $user)
    {
        $address = $this->req('POST', self::CUSTOMER_ADDRESS_ENDPOINT, [
            'CustomerID' => $user->getPosID(),
            'Name' => 'Main address',
            'AddressLine1' => $user->__get('street'),
            'AddressLine2' => $user->__get('post_code').' - '.$user->__get('city'),
            'Town' => $user->__get('city'),
            'PostCode' => $user->__get('post_code')
        ]);

        $this->req('PUT', self::CUSTOMER_ENDPOINT.$user->getPosID(), [
            'MainAddressID' => $address['CustomerAddressID']
        ]);
    }

    /**
     * #outgoing
     *
     * Add new order to ePOS NOW database
     *
     * @param Order $order
     */
    public function createNewOrder(Order $order)
    {
        if($order->getUser()) $this->createNewUser($order->getUser());

        $transactionItems = [];

        switch($order->getPayment()) {
            case 'classic': $tenderType = self::TENDER_ID_CARD; break;
            case 'paypal': $tenderType = self::TENDER_ID_PAYPAL; break;
        }

        if(!isset($tenderType)) {
            $this->_log('Cannot create tender with type: '.$order->getPayment().'. Missing map in '.self::class);
            return;
        }

        foreach($order->getDeals() as $i => $deal) {
            $price = $deal->getPrice()/$deal->getWeight();

            if($order->getDiscountName() && $order->getDiscountPercent()) {
                $price *= (100-$order->getDiscountPercent())/100;
            }
            if($deal->getOrder()->getDiscountCodeName() && $deal->getOrder()->getDiscountCodePercent()) {
                $price *= (100-$order->getDiscountCodePercent())/100;
            }

            $transactionItems[$i] = [
                'ProductID' => $deal->getVariant()->getProduct()->getCode(),
                'Quantity' => $deal->getWeight()*$deal->getAmount(),
                'Price' => $price
            ];
        }

        $deliveryCost = $order->getDeliveryCost();
        if($order->getDiscountName() && $order->getDiscountPercent()) {
            $deliveryCost *= (100-$order->getDiscountPercent())/100;
        }
        if($order->getDiscountCodeName() && $order->getDiscountCodePercent()) {
            $deliveryCost *= (100-$order->getDiscountCodePercent())/100;
        }

        $transaction = $this->req('POST', self::TRANSACTION_FULL_ENDPOINT, [
            'DateTime' => $order->getCreatedTime()->format('Y-m-d H:i:s'),
            'CustomerID' => $order->getUser()->getPosID(),
            'EatOut' => 2, // delivery
            'TransactionItems' => $transactionItems,
            'Tenders' => [
                ['TypeID' => $tenderType,
                    'Amount' => $order->getTotalCost()]
            ],
            'BaseItems' => [
                ['ItemTypeID' => 51, // Service Charge
                    'Amount' => $deliveryCost,
                    'Notes' => $order->getDeliveryName()]
            ]
        ]);
        if($transaction === null) return;

        $order->setPosID($transaction['TransactionID']);
        $this->em->persist($order);
        $this->em->flush();
    }

    /**
     * #outgoing
     *
     * Confirm order in ePOS NOW database
     *
     * @param Order $order
     */
    public function confirmOrder(Order $order)
    {
        $this->req('PUT', self::TRANSACTION_ENDPOINT.$order->getPosID(), [
            'PaymentStatus' => 'Complete'
        ]);
    }

    /**
     * #outgoing
     * 
     * Cancel order in ePOS NOW database
     *
     * @param Order $order
     */
    public function cancelOrder(Order $order)
    {
        $this->req('PUT', self::TRANSACTION_ENDPOINT.$order->getPosID(), [
            'PaymentStatus' => 'Hold'
        ]);
    }


    /**
     * #incoming
     *
     * Update product details invoking by web-hooks from ePOS NOW system
     *
     * @param array $product
     */
    public function changeSingleProduct(array $product)
    {
        $productInDatabase = $this->em->getRepository('ProAppsPanelBundle:Product')->findOneBy(['code' => $product['ProductID']]);
        if(!$productInDatabase) return;

        $productInDatabase
            ->setName($product['Description'])
            ->setPricePerKilo($product['SalePrice']*1000);

        $this->em->persist($productInDatabase);
        $this->em->flush();

        $this->_log('Simple update of product: '.$product['Description'].' with price per kilo: '.$productInDatabase->getPricePerKilo(), true);
    }

    /**
     * #incoming
     *
     * Update current product stock invoking by web-hooks from ePOS NOW system
     *
     * @param array $stock
     */
    public function changeProductStock(array $stock)
    {
        if($stock['LocationID'] != self::LOCATION_JM_ID) return;

        $total = 0;
        foreach($stock['ProductStocks'] as $productStock) $total += $productStock['CurrentStock'];

        $products = $this->em->getRepository('ProAppsPanelBundle:Product')->findBy(['posMasterID' => $stock['ProductID']]);
        foreach($products as $product) {
            $product->setMinimalQuantity($stock['MinStock']);
            $this->em->persist($product);
            $this->em->flush();

            $this->createVariantsAmounts($product, $total);
        }
    }

    /**
     * Total synchronize stocks
     */
    public function totalSynchronize()
    {
        $page = 0;
        while(1) {
            $stocks = $this->req('GET', self::STOCK_ENDPOINT.($page ? '?page='.$page : ''));
            if(!$stocks) break;
            foreach($stocks as $stock) {
                if($stock['LocationID'] != self::LOCATION_JM_ID) continue;
                $products = $this->em->getRepository('ProAppsPanelBundle:Product')->findBy(['posMasterID' => $stock['ProductID']]);
                foreach($products as $product) {
                    $this->createVariantsAmounts($product, $stock['CurrentStock']);
                }
            }
            $page++;
        }
    }

    /**
     * Create evenly amounts of variants from total weight of product
     *
     * @param Product $product
     * @param $total
     */
    private function createVariantsAmounts(Product &$product, $total)
    {
        foreach($product->getVariants() as $variant) $variant->setAmount(0);
        $tmpTotal = 0;
        $periodicIndex = 0;
        while($tmpTotal < $total) {
            $current = $product->getVariants()->get($periodicIndex);
            $current->setAmount($current->getAmount()+1);
            if($periodicIndex++ >= $product->getVariants()->count()-1) $periodicIndex=0;
            $tmpTotal += $current->getWeight();
        }

        $this->em->persist($product);
        $this->em->flush();

        $this->_log('Update of product stock in product: '.$product->getName().' with variants: ', true);
        foreach($product->getVariants() as $variant) {
            $this->_log('   → '.$variant->getWeight().' x '.$variant->getAmount(), true);
        }
    }


    /**
     * Request REST API with given method, endpoint and optional parameters
     *
     * @param $method
     * @param $url
     * @param array $params
     * @return array|null
     */
    private function req($method, $url, array $params = [])
    {
        $this->_log('Requesting {'.$method.'} '.$url.($params ? ' with params:' : ''));
        if($params) $this->_log(json_encode($params, JSON_PRETTY_PRINT));

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization : Basic '.$this->token, 'Content-Type: Application/json']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));

        $output = curl_exec($curl);

        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->_log($code == 201 || $code == 200 ? 'Response OK!' : 'Response ERR: '.$code.'!');

        $response = json_decode($output, JSON_OBJECT_AS_ARRAY);
        if($response === null) {
            $this->_log('Failed to JSON parse response: '.$output);
            return null;
        } else {
            if($code != 200 && $code != 201) $this->_log('ERR: '.json_encode($response, JSON_PRETTY_PRINT));
        }

        return $response;
    }

    /**
     * Add log-line
     *
     * @param string $line
     * @param bool $incoming
     * @return Epos
     */
    private function _log($line, $incoming = false)
    {
        $line = '['.\DateTime::createFromFormat('U.u', microtime(true))->format('Y-m-d H:i:s.u').'] '.($incoming ? '#incoming ' : '#outgoing ').$line.PHP_EOL;
        file_put_contents(self::LOG_FILE_PATH, $line, FILE_APPEND);
        return $this;
    }
}