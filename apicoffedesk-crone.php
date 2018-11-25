<?php

/**
 * need to use absolute paths on crons. At nazwa.pl for 123kawa.pl/sklep this is /home/konto/ftp/sklep/
 */
require_once('/home/konto/ftp/sklep/wp-config.php');
require_once('/home/konto/ftp/sklep/apicoffedesk-config.php');

class APICoffedesk
{
    private $resultGetProductCURL;
    private $successConnectAPI;

    public function __construct()
    {
        $this->polePrywatne = API_KEY_COFFEDESK;
    }

    public function getSuccessConnectAPI()
    {
        return $this->successConnectAPI;
    }

    public function getProductCoffedesk($id_product)
    {
        $params = array(
            'api_key' => API_KEY_COFFEDESK,
            'action' => 'getProducts',
            'data' => array(
                'id' => $id_product
            )
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, API_URL_COFFEDESK);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $this->resultGetProductCURL = curl_exec($ch);
        if (curl_errno($ch) !== 0) {

            $this->successConnectAPI = false;
            error_log('cURL error when connecting to ' . API_URL_COFFEDESK . ': ' . curl_error($ch));
            echo '<script>console.error("cURL error when connecting to ' . API_URL_COFFEDESK . ': ' . curl_error($ch) . '");</script>';
        } else {;
            $this->successConnectAPI = true;
        }

        curl_close($ch);

        if ($this->resultGetProductCURL == '<h1>Internal server error</h1>') {
            echo '<script>console.error("WYSTĄPIŁ WEWNĘTRZNY BŁĄD Połączenia z API Coffedesk. Prawdopodobnie błędny klucz.");</script>';
            $this->successConnectAPI = false;
        }

        if ($this->resultGetProductCURL == 'Product not found') {
            echo '<script>console.error("Nie znaleziono produktu w API Coffedesk");</script>';
            $this->successConnectAPI = false;
        }

        $d = json_decode($this->resultGetProductCURL, true);
        if (isset($d["error"])) {
            echo '<script>console.error("' . $d["error"] . '. Prawdopodobnie zły parametr akcji do API Coffedesk");</script>';
            $this->successConnectAPI = false;
        }

        return $this->decodeProduct($this->resultGetProductCURL);
    }

    private function decodeProduct($result)
    {
        $data = json_decode($result);
        foreach ($data as $name => $value) {
            if ($name == "products") {
                $array = json_decode(json_encode($value), true);
                $coffed["name"] = $array[0]["name"];
                $coffed["quantity"] = $array[0]["quantity"];
                $coffed["regularPrice"] = $array[0]["priceTaxIncl"];
            //$coffed["regularPrice"] = round(($array[0]["priceTaxIncl"]/(($array[0]["vat"]/100)+1)),2);
                if (isset($array[0]["pricePromotionalTaxIncl"])) {
                    $coffed["salePrice"] = $array[0]["pricePromotionalTaxIncl"];
                    //$coffed["salePrice"] = round(($array[0]["pricePromotionalTaxIncl"]/(($array[0]["vat"]/100)+1)),2);
                } else {
                    $coffed["salePrice"] = null;
                }
            }
        }
        return $coffed;
    }

}

class Shop123kawaSync
{
    private $successConnectDB;
    private $connPDO;

    public function __construct()
    {
        try {
            $this->connPDO = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
            // set the PDO error mode to exception
            $this->connPDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->successConnectDB = true;
        } catch (PDOException $e) {
            $this->successConnectDB = false;
            echo "Connection failed: " . $e->getMessage();
        }
    }

    /**
     * Downloading products from the database
     */
    public function getProducts123kawa()
    {
        $stmt = $this->connPDO->prepare('SELECT object_id AS id_post, slug AS id_coffedesk, (SELECT meta_value FROM wpxo_postmeta WHERE post_id = rs.object_id AND meta_key = "_stock_status") AS stock_status, (SELECT meta_value FROM wpxo_postmeta WHERE post_id = rs.object_id AND meta_key = "_stock") AS stock, (SELECT meta_value FROM wpxo_postmeta WHERE post_id = rs.object_id AND meta_key = "_regular_price") AS regular_price, (SELECT meta_value FROM wpxo_postmeta WHERE post_id = rs.object_id AND meta_key = "_sale_price") AS sale_price, (SELECT meta_value FROM wpxo_postmeta WHERE post_id = rs.object_id AND meta_key = "_price") AS price, (SELECT post_title FROM wpxo_posts WHERE ID = rs.object_id) AS title FROM wpxo_term_taxonomy AS tax INNER JOIN wpxo_term_relationships AS rs USING(term_taxonomy_id) INNER JOIN wpxo_terms AS ter USING(term_id) WHERE tax.taxonomy="pa_id_product"');
        $stmt->execute();
    // set the resulting array to associative
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }
    /**
     * Compare product and update data
     */
    public function compareProducts()
    {
        $prod = new APICoffedesk();
        foreach ($this->getProducts123kawa() as $k => $product123kawa) {
            $productCoffedesk = $prod->getProductCoffedesk($product123kawa["id_coffedesk"]);
            if ($prod->getSuccessConnectAPI() == true and $this->successConnectDB == true) {
                $changeRegularPrice = false;
                $changeSalePrice = false;
                $changeStock = false;

                if ($product123kawa["regular_price"] <> $productCoffedesk["regularPrice"] and $productCoffedesk["regularPrice"] <> null and $productCoffedesk["regularPrice"] > 0) {
                    $this->updateProduct123kawaRegularPrice($product123kawa["id_post"], $productCoffedesk["regularPrice"], $product123kawa["sale_price"]);
                    $changeRegularPrice = true;
                }

                if ($product123kawa["sale_price"] <> $productCoffedesk["salePrice"]) {
                    if ($productCoffedesk["salePrice"] == null) {
                        $this->updateProduct123kawaSalePriceDel($product123kawa["id_post"], $product123kawa["regular_price"]);
                    } else {
                        $this->updateProduct123kawaSalePrice($product123kawa["id_post"], $productCoffedesk["salePrice"]);
                    }
                    $changeSalePrice = true;
                }
                if ($product123kawa["stock"] <> $productCoffedesk["quantity"]) {
                    $this->updateProduct123kawaQuantity($product123kawa["id_post"], $productCoffedesk["quantity"]);
                    $changeStock = true;
                }
                if ($changeRegularPrice == true or $changeSalePrice == true or $changeStock == true) {
                    $t = 'Akt. prod.: ' . $product123kawa["id_post"] . ' - ' . $product123kawa["title"] . ' - ';
                    if ($changeRegularPrice == true) {
                        $t = $t . "Cena podst. z " . $product123kawa["regular_price"] . " na " . $productCoffedesk["regularPrice"] . " ";
                    }
                    if ($changeSalePrice == true) {
                        $t = $t . "Cena PROM. z " . $product123kawa["sale_price"] . " na " . $productCoffedesk["salePrice"] . " ";
                    }
                    if ($changeStock == true) {
                        $t = $t . "Ilosc. z " . $product123kawa["stock"] . " na " . $productCoffedesk["quantity"] . " ";
                    }

                    echo $t . PHP_EOL;
                }
            }
        }
        if ($prod->getSuccessConnectAPI() == false) {
            echo "<p>BŁĄD. Problem z połączeniem API Coffedesk</p>";
        }
    }

    public function updateProduct123kawaRegularPrice($id_prod, $price, $salePrice)
    {
        $query = 'UPDATE wpxo_postmeta SET meta_value="' . $price . '" WHERE post_id="' . $id_prod . '" AND meta_key="_regular_price"';
        $stmt = $this->connPDO->prepare($query);
        $stmt->execute();
        if ($salePrice == null) {
            $this->updateProduct123kawaPrice($id_prod, $price);
        }

    }

    public function updateProduct123kawaSalePrice($id_prod, $price)
    {
        $query = 'UPDATE wpxo_postmeta SET meta_value="' . $price . '" WHERE post_id="' . $id_prod . '" AND meta_key="_sale_price"';
        $stmt = $this->connPDO->prepare($query);
        $stmt->execute();
        $this->updateProduct123kawaPrice($id_prod, $price);
    }

    public function updateProduct123kawaSalePriceDel($id_prod, $price)
    {
        $this->updateProduct123kawaSalePrice($id_prod, null);
        $this->updateProduct123kawaPrice($id_prod, $price);
    }

    public function updateProduct123kawaPrice($id_prod, $price)
    {
        $query = 'UPDATE wpxo_postmeta SET meta_value="' . $price . '" WHERE post_id="' . $id_prod . '" AND meta_key="_price"';
        $stmt = $this->connPDO->prepare($query);
        $stmt->execute();
    }

    public function updateProduct123kawaQuantity($id_prod, $quantity)
    {
        $query = 'UPDATE wpxo_postmeta SET meta_value="' . $quantity . '" WHERE post_id="' . $id_prod . '" AND meta_key="_stock"';
        $stmt = $this->connPDO->prepare($query);
        $this->updateProduct123kawaStock($id_prod, $quantity);
        return $stmt->execute();
    }

    public function updateProduct123kawaStock($id_prod, $quantity)
    {
        if ($quantity <= 0) {
            $stock = "outofstock";
        }
        if ($quantity > 0) {
            $stock = "instock";
        }
        $query = 'UPDATE wpxo_postmeta SET meta_value="' . $stock . '" WHERE post_id="' . $id_prod . '" AND meta_key="_stock_status"';
        $stmt = $this->connPDO->prepare($query);
        return $stmt->execute();
    }
    /**
     * Comparison Price nad Stock, without making changes
     */

    public function comparison()
    {
        $prod = new APICoffedesk();
        echo "<table style='border: solid 1px black;'>";
        echo "<tr><th>Id</th><th>Nazwa</th><th>Cena 123kawa</th><th>Cena Coffedesk</th><th>Cena PROM 123kawa</th><th>Cena PROM CoffeDesk</th><th>Il. na mag. 123kawa</th><th>Il. na mag. CoffeDesk</th></tr>";
        foreach ($this->getProducts123kawa() as $k => $product123kawa) {
            $productCoffedesk = $prod->getProductCoffedesk($product123kawa["id_coffedesk"]);
            if ($prod->getSuccessConnectAPI() == true) {
                echo "<tr><td style='width:50px;border:1px solid black;' >" . $product123kawa["id_post"] . "</td>";
                if ($product123kawa["regular_price"] <> $productCoffedesk["regularPrice"] or $product123kawa["sale_price"] <> $productCoffedesk["salePrice"] or $product123kawa["stock"] <> $productCoffedesk["quantity"]) {
                    echo "<td style='width:400px;border:1px solid black; color: red; font-weight: bold;' >" . $product123kawa["title"] . "</td>";
                } else {
                    echo "<td style='width:400px;border:1px solid black;' >" . $product123kawa["title"] . "</td>";
                }
                echo "<td style='width:100px;border:1px solid black;' >" . $product123kawa["regular_price"] . "</td>";
                echo "<td style='width:100px;border:1px solid black;' >" . $productCoffedesk["regularPrice"] . "</td>";
                echo "<td style='width:100px;border:1px solid black;' >" . $product123kawa["sale_price"] . "</td>";
                echo "<td style='width:100px;border:1px solid black;' >" . $productCoffedesk["salePrice"] . "</td>";
                echo "<td style='width:100px;border:1px solid black;' >" . $product123kawa["stock"] . "</td>";
                if ($productCoffedesk["quantity"] == 0) {
                    echo "<td style='width:100px;border:1px solid black; color: red; font-weight: bold;' >" . $productCoffedesk["quantity"] . "</td></tr>";
                } else {
                    echo "<td style='width:100px;border:1px solid black;' >" . $productCoffedesk["quantity"] . "</td></tr>";
                }
            }
        }
        if ($prod->getSuccessConnectAPI() == false) {
            echo "BŁĄD. Problem z połączeniem API Coffedesk";
        }
        echo "</table>";
    }


}
$p = new Shop123kawaSync();
$p->compareProducts();
?>