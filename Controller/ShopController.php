<?php

class ShopController extends ShopAppController
{

    public $components = array('Session', 'Shop.DiscountVoucher', 'History');

    /*
    * ======== Page principale de la boutique ===========
    */

    function index($category = false)
    { // Index de la boutique

        $title_for_layout = $this->Lang->get('SHOP__TITLE');
        if ($category) {
            $this->set(compact('category'));
        }
        $this->layout = $this->Configuration->getKey('layout'); // On charge le thème configuré
        $this->loadModel('Shop.Item'); // le model des articles
        $this->loadModel('Shop.Category'); // le model des catégories
        $search_items = $this->Item->find('all', array(
            'conditions' => array(
                'OR' => array(
                    'display IS NULL',
                    'display = 1'
                )
            )
        )); // on cherche tous les items et on envoie à la vue
        $search_categories = $this->Category->find('all'); // on cherche toutes les catégories et on envoie à la vue

        $search_first_category = $this->Category->find('first'); //
        $search_first_category = @$search_first_category['Category']['id']; //

        $this->loadModel('Shop.Paypal');
        $paypal_offers = $this->Paypal->find('all');

        $this->loadModel('Shop.Starpass');
        $starpass_offers = $this->Starpass->find('all');

        $this->loadModel('Shop.DedipassConfig');
        $findDedipassConfig = $this->DedipassConfig->find('first');
        $dedipass = (!empty($findDedipassConfig) && isset($findDedipassConfig['DedipassConfig']['status']) && $findDedipassConfig['DedipassConfig']['status']) ? true : false;

        $this->loadModel('Shop.Paysafecard');
        $paysafecard_enabled = $this->Paysafecard->find('all', array('conditions' => array('amount' => '0', 'code' => 'disable', 'user_id' => 0, 'created' => '1990/00/00 15:00:00')));
        if (!empty($paysafecard_enabled)) {
            $paysafecard_enabled = false;
        } else {
            $paysafecard_enabled = true;
        }

        $money = 0;
        if ($this->isConnected) {
            $money = $this->User->getKey('money') . ' ';
            $money += ($this->User->getKey('money') == 1 OR $this->User->getKey('money') == 0) ? $this->Configuration->getMoneyName(false) : $this->Configuration->getMoneyName();
        }

        $vouchers = $this->DiscountVoucher;

        $singular_money = $this->Configuration->getMoneyName(false);
        $plural_money = $this->Configuration->getMoneyName();

        $this->set(compact('dedipass', 'paysafecard_enabled', 'money', 'starpass_offers', 'paypal_offers', 'search_first_category', 'search_categories', 'search_items', 'title_for_layout', 'vouchers', 'singular_money', 'plural_money'));
    }


    /*
    * ======== Affichage d'un article dans le modal ===========
    */

    function ajax_get($id)
    { // Permet d'afficher le contenu du modal avant l'achat (ajax)
        $this->response->type('json');
        $this->autoRender = false;
        if ($this->isConnected AND $this->Permissions->can('CAN_BUY')) { // si l'utilisateur est connecté
            $this->loadModel('Shop.Item'); // je charge le model des articles
            $search_item = $this->Item->find('all', array('conditions' => array('id' => $id))); // je cherche l'article selon l'id
            $money = ($search_item['0']['Item']['price'] == 1) ? $this->Configuration->getMoneyName(false) : $this->Configuration->getMoneyName();// je dis que la variable $money = le nom de la money au pluriel ou singulier selon le prix
            if (!empty($search_item[0]['Item']['servers'])) {
                $this->loadModel('Server');
                $search_servers_list = $this->Server->find('all');
                foreach ($search_servers_list as $key => $value) {
                    $servers_list[$value['Server']['id']] = $value['Server']['name'];
                }
                $search_item[0]['Item']['servers'] = unserialize($search_item[0]['Item']['servers']);
                $servers = '';
                $i = 0;
                foreach ($search_item[0]['Item']['servers'] as $serverId) {
                    $i++;
                    if (isset($servers_list) && !isset($servers_list[$serverId]))
                        continue;
                    $servers = $servers . $servers_list[$serverId];
                    if ($i < count($search_item[0]['Item']['servers'])) {
                        $servers = $servers . ', ';
                    }
                }
            }

            $item_price = $search_item['0']['Item']['price'];

            $affich_server = (!empty($search_item[0]['Item']['servers']) && $search_item[0]['Item']['display_server']) ? true : false;
            $multiple_buy = (!empty($search_item[0]['Item']['multiple_buy']) && $search_item[0]['Item']['multiple_buy']) ? true : false;
            $reductional_items_func = (!empty($search_item[0]['Item']['reductional_items']) && !is_bool(unserialize($search_item[0]['Item']['reductional_items']))) ? true : false;
            $reductional_items = false;
            if ($reductional_items_func) {

                $this->loadModel('Shop.ItemsBuyHistory');

                $reductional_items_list = unserialize($search_item[0]['Item']['reductional_items']);
                $reductional_items_list_display = array();
                // on parcours tous les articles pour voir si ils ont été achetés
                $reductional_items = true; // de base on dis que c'est okay
                $reduction = 0; // 0 de réduction
                foreach ($reductional_items_list as $key => $value) {

                    $findItem = $this->Item->find('first', array('conditions' => array('id' => $value)));
                    if (empty($findItem)) {
                        $reductional_items = false;
                        break;
                    }

                    $findHistory = $this->ItemsBuyHistory->find('first', array('conditions' => array('user_id' => $this->User->getKey('id'), 'item_id' => $findItem['Item']['id'])));
                    if (empty($findHistory)) {
                        $reductional_items = false;
                        break;
                    }

                    $reduction = +$findItem['Item']['price'];
                    $reductional_items_list_display[] = $findItem['Item']['name'];

                    unset($findItem);

                }

                if ($reductional_items) {
                    $item_price = $item_price - $reduction;

                    $reduction = $reduction . ' ' . $this->Configuration->getMoneyName();
                    $reductional_items_list = '<i>' . implode('</i>, <i>', $reductional_items_list_display) . '</i>';
                    $reductional_items_list = $this->Lang->get('SHOP__ITEM_REDUCTIONAL_ITEMS_LIST', array('{ITEMS_LIST}' => $reductional_items_list, '{REDUCTION}' => $reduction));

                }
            }

            $add_to_cart = (!empty($search_item[0]['Item']['cart']) && $search_item[0]['Item']['cart']) ? true : false;

            //On récupére l'element
            $filename_theme = APP . DS . 'View' . DS . 'Themed' . DS . $this->Configuration->getKey('theme') . DS . 'Plugin' . DS . 'Shop' . DS . 'Elements' . DS . 'modal_buy.ctp';
            if (file_exists($filename_theme)) {
                $element_content = file_get_contents($filename_theme);
            } else {
                $element_content = file_get_contents($this->EyPlugin->pluginsFolder . DS . 'Shop' . DS . 'View' . DS . 'Elements' . DS . 'modal_buy.ctp');
            }

            // On remplace les messages de langues

            $i = 0;
            $count = substr_count($element_content, '{LANG-');
            while ($i < $count) {
                $i++;

                $element_explode_for_lang = explode('{LANG-', $element_content);
                $element_explode_for_lang = explode('}', $element_explode_for_lang[1])[0];

                $element_content = str_replace('{LANG-' . $element_explode_for_lang . '}', $this->Lang->get($element_explode_for_lang), $element_content);

            }

            // On remplace les variables
            $servers = (!isset($servers)) ? null : $servers;

            $vars = array(
                '{ITEM_NAME}' => $search_item['0']['Item']['name'],
                '{ITEM_DESCRIPTION}' => nl2br($search_item['0']['Item']['description']),
                '{ITEM_SERVERS}' => $servers,
                '{ITEM_PRICE}' => $item_price,
                '{SITE_MONEY}' => $money,
                '{ITEM_ID}' => $search_item['0']['Item']['id'],
                '{ITEM_IMG_URL}' => $search_item['0']['Item']['img_url']
            );
            $element_content = strtr($element_content, $vars);

            // La condition d'affichage de serveur
            $element_explode_for_server = explode('[IF AFFICH_SERVER]', $element_content);
            $element_explode_for_server = explode('[/IF AFFICH_SERVER]', $element_explode_for_server[1])[0];

            $search_server = '[IF AFFICH_SERVER]' . $element_explode_for_server . '[/IF AFFICH_SERVER]';
            $element_content = ($affich_server) ? str_replace($search_server, $element_explode_for_server, $element_content) : str_replace($search_server, '', $element_content);

            // La condition d'affichage de l'input achat multiple
            $element_explode_for_multiple_buy = explode('[IF MULTIPLE_BUY]', $element_content);
            $element_explode_for_multiple_buy = explode('[/IF MULTIPLE_BUY]', $element_explode_for_multiple_buy[1])[0];

            $search_multiple_buy = '[IF MULTIPLE_BUY]' . $element_explode_for_multiple_buy . '[/IF MULTIPLE_BUY]';
            $element_content = ($multiple_buy) ? str_replace($search_multiple_buy, $element_explode_for_multiple_buy, $element_content) : str_replace($search_multiple_buy, '', $element_content);

            // La condition d'affichage de l'ajout au pnier
            $element_explode_for_add_to_cart = explode('[IF ADD_TO_CART]', $element_content);
            $element_explode_for_add_to_cart = explode('[/IF ADD_TO_CART]', $element_explode_for_add_to_cart[1])[0];

            $search_add_to_cart = '[IF ADD_TO_CART]' . $element_explode_for_add_to_cart . '[/IF ADD_TO_CART]';
            $element_content = ($add_to_cart) ? str_replace($search_add_to_cart, $element_explode_for_add_to_cart, $element_content) : str_replace($search_add_to_cart, '', $element_content);

            // La condition d'affichage du message de réduction de prix si articles achetés
            $element_explode_for_reductional_items = explode('[IF REDUCTIONAL_ITEMS]', $element_content);
            $element_explode_for_reductional_items = explode('[/IF REDUCTIONAL_ITEMS]', $element_explode_for_reductional_items[1])[0];

            $search_reductional_items = '[IF REDUCTIONAL_ITEMS]' . $element_explode_for_reductional_items . '[/IF REDUCTIONAL_ITEMS]';
            $element_content = ($reductional_items) ? str_replace($search_reductional_items, $element_explode_for_reductional_items, $element_content) : str_replace($search_reductional_items, '', $element_content);
            if ($reductional_items) {
                $element_content = str_replace('{REDUCTIONAL_ITEMS_LIST}', $reductional_items_list, $element_content);
            }


            $this->response->body(json_encode(array('statut' => true, 'html' => $element_content, 'item_infos' => array('id' => $search_item['0']['Item']['id'], 'name' => $search_item['0']['Item']['name'], 'price' => $item_price))));

        } else {
            $this->response->body(json_encode(array('statut' => false, 'html' => '<div class="alert alert-danger">' . $this->Lang->get('USER__ERROR_MUST_BE_LOGGED') . '</div>'))); // si il n'est pas connecté
        }
    }


    /*
    * ======== Achat d'un article depuis le modal ===========
    */

    public function checkVoucher($code = null, $items_id = null, $quantities = 1)
    {
        $this->autoRender = false;
        $this->response->type('json');

        if (!empty($code) && !empty($items_id)) {

            $this->loadModel('Shop.Item');

            $items_id = explode(',', $items_id);
            $quantities = explode(',', $quantities);
            $items = array_combine($items_id, $quantities);
            $total_price = 0;
            $new_price = 0;

            foreach ($items as $item_id => $quantity) {

                $findItem = $this->Item->find('first', array('conditions' => array('id' => $item_id)));

                if (!empty($findItem)) {

                    // On gère la quantité
                    $total_price += $findItem['Item']['price'] * $quantity;

                    $i = 0;
                    while ($i < $quantity) {

                        $getVoucherPrice = $this->DiscountVoucher->getNewPrice($findItem['Item']['id'], $code);

                        if ($getVoucherPrice['status']) {
                            $new_price = $new_price + $getVoucherPrice['price'];
                        } else {
                            $new_price = $new_price + $findItem['Item']['price']; // erreur
                        }

                        $i++;
                    }


                    /*
                        On gère les réductions de prix
                    */
                    $reduction = $this->Item->getReductionWithReductionalItems($findItem['Item'], $this->User->getKey('id'));
                    // on effectue la reduction
                    $new_price = $new_price - $reduction * $quantity;

                    if ($new_price < 0) {
                        $new_price = 0;
                    }

                }
            }

            $this->response->body(json_encode(array('price' => $new_price)));

        }

        return;

    }


    /*
    * ======== Achat d'un article depuis le modal ===========
    */

    function buy_ajax()
    {
        $this->autoRender = false;
        $this->response->type('json');
        if ($this->request->is('ajax')) {
            if ($this->isConnected && $this->Permissions->can('CAN_BUY')) {
                if (!empty($this->request->data['items'])) {
                    /*
                      Traitement préalable - On ajoute de la quantité si un article est envoyé plusieurs fois (tentative de cheat)
                    */
                    $items_in_cart = array();
                    foreach ($this->request->data['items'] as $item) {

                        if (isset($items_in_cart[$item['item_id']])) {
                            if (isset($items_in_cart[$item['item_id']]['quantity'])) {
                                intval($items_in_cart[$item['item_id']]['quantity']);
                                $items_in_cart[$item['item_id']]['quantity']++;
                            } else {
                                $items_in_cart[$item['item_id']]['quantity'] = 1;
                            }
                        } else {
                            $items_in_cart[$item['item_id']] = $item;
                        }

                    }
                    /*
                    ===
                    */

                    // Nos variables de traitement
                    $items = array();
                    $total_price = 0;
                    $servers = array();

                    $give_skin = false;
                    $give_cape = false;

                    $voucher_code = (isset($this->request->data['code']) && !empty($this->request->data['code'])) ? $this->request->data['code'] : NULL;
                    $voucher_code_used = false;
                    $voucher_used_count = 0;
                    $voucher_reduction = 0;

                    // On récupère le broadcast global
                    $this->loadModel('Shop.ItemsConfig');
                    $config = $this->ItemsConfig->find('first');
                    if (empty($config)) {
                        $config['ItemsConfig']['broadcast_global'] = '';
                    }


                    // On parcours les articles donnés
                    $this->loadModel('Shop.Item');
                    $this->loadModel('Shop.ItemsBuyHistory');

                    $i = 0;
                    foreach ($items_in_cart as $key => $value) {
                        if (!isset($value['quantity']) || $value['quantity'] > 0) {

                            $findItem = $this->Item->find('first', array('conditions' => array('id' => $value['item_id'])));
                            if (!empty($findItem)) {

                                if (isset($value['quantity']) && $value['quantity'] > 1 && (empty($findItem['Item']['multiple_buy']) || !$findItem['Item']['multiple_buy'])) {
                                    $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__ITEM_CANT_BUY_MULTIPLE', array('{ITEM_NAME}' => $findItem['Item']['name'])))));
                                    return;
                                }

                                if (count($this->request->data['items']) > 1 && (empty($findItem['Item']['cart']) || $findItem['Item']['cart'] == 0)) {
                                    $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__ITEM_CANT_ADDED_TO_CART', array('{ITEM_NAME}' => $findItem['Item']['name'])))));
                                    return;
                                }

                                /*
                                  On vérifie la limite d'achat
                                */
                                if (isset($findItem['Item']['buy_limit']) && $findItem['Item']['buy_limit'] > 0) {
                                    $buy_count = $this->ItemsBuyHistory->find('count', array('conditions' => array('user_id' => $this->User->getKey('id'), 'item_id' => $findItem['Item']['id'])));
                                    if ($buy_count >= $findItem['Item']['buy_limit']) {
                                        $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__ITEM_CANT_BUY_LIMIT', array('{ITEM_NAME}' => $findItem['Item']['name'], '{LIMIT}' => $findItem['Item']['buy_limit'])))));
                                        return;
                                    }
                                    unset($buy_count);
                                }

                                /*
                                  On vérifie l'intervalle de buy
                                */
                                if (isset($findItem['Item']['wait_time']) && !empty($findItem['Item']['wait_time'])) {
                                    $last_buy = $this->ItemsBuyHistory->find('first', array('conditions' => array('user_id' => $this->User->getKey('id'), 'item_id' => $findItem['Item']['id']), 'order' => 'id desc'));
                                    if (!empty($last_buy)) {

                                        if (strtotime('+' . $findItem['Item']['wait_time'], strtotime($last_buy['ItemsBuyHistory']['created'])) > time()) {

                                            $wait_time = explode(' ', $findItem['Item']['wait_time']);
                                            $wait_time = $wait_time[0] . ' ' . $this->Lang->get('GLOBAL__DATE_R_' . strtoupper($wait_time[1]));
                                            $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__ITEM_CANT_BUY_WAIT_TIME', array('{ITEM_NAME}' => $findItem['Item']['name'], '{WAIT_TIME}' => $wait_time)))));
                                            return;

                                        }

                                    }
                                    unset($last_buy);
                                }

                                /*
                                  On vérifie les pré-requis
                                */
                                $prerequisites = $this->Item->checkPrerequisites($findItem['Item'], $this->User->getKey('id'));
                                if ($prerequisites !== TRUE) {
                                    $this->response->body(json_encode(array(
                                        'statut' => false,
                                        'msg' => $this->Lang->get('SHOP__ITEM_CANT_BUY_PREREQUISITES_' . $prerequisites['error'], array('{ITEMS}' => $prerequisites['items_list']))
                                    )));
                                    return;
                                }
                                /*
                                ===
                                */

                                $items[$i] = $findItem['Item'];
                                $items[$i]['servers'] = (is_array(unserialize($items[$i]['servers']))) ? unserialize($items[$i]['servers']) : array();

                                if ($findItem['Item']['give_skin']) {
                                    $give_skin = true;
                                }
                                if ($findItem['Item']['give_cape']) {
                                    $give_cape = true;
                                }

                                if (!isset($findItem['Item']['broadcast_global']) || $findItem['Item']['broadcast_global']) {
                                    // Donc si on doit broadcast
                                    if (isset($config['ItemsConfig']['broadcast_global']) && !empty($config['ItemsConfig']['broadcast_global'])) { // Si il est pas vide dans la config
                                        $msg = str_replace('{PLAYER}', $this->User->getKey('pseudo'), $config['ItemsConfig']['broadcast_global']);
                                        $quantity = (isset($value['quantity'])) ? $value['quantity'] : 1;
                                        $msg = str_replace('{QUANTITY}', $quantity, $msg);
                                        $msg = str_replace('{ITEM_NAME}', $findItem['Item']['name'], $msg);

                                        $servers_names = array();
                                        foreach ($items[$i]['servers'] as $serverid) {
                                            $servers_names[] = @ClassRegistry::init('Server')->find('first', array('conditions' => array('id' => $serverid)))['Server']['name'];
                                        }

                                        $servers_names = implode(', ', $servers_names);
                                        $msg = str_replace('{SERVERNAME}', $servers_names, $msg);
                                        if (isset($items[$i]['broadcast'])) {
                                            $items[$i]['broadcast'] .= $msg;
                                        } else {
                                            $items[$i]['broadcast'] = $msg;
                                        }
                                        unset($msg);
                                    }
                                }
                                /*
                                === Code promotionnel ===
                                */
                                if (!empty($this->request->data['code'])) {

                                    $getVoucherPrice = $this->DiscountVoucher->getNewPrice($items[$i]['id'], $voucher_code);

                                    if ($getVoucherPrice['status']) {
                                        $voucher_code_used = true;
                                        $voucher_used_count++;
                                        $voucher_reduction += $items[$i]['price'] - $getVoucherPrice['price'];
                                        $items[$i]['price'] = $getVoucherPrice['price'];
                                    }

                                }
                                /*
                                ===
                                */

                                /*
                                   === Réduction d'articles ===
                                */

                                $reduction = $this->Item->getReductionWithReductionalItems($findItem['Item'], $this->User->getKey('id'));
                                // on effectue la reduction
                                $items[$i]['price'] = $items[$i]['price'] - $reduction;
                                /*
                                ===
                                */

                                $total_price += $items[$i]['price'];

                                foreach ($items[$i]['servers'] as $k => $server_id) {
                                    if (!in_array($server_id, $servers)) {
                                        $servers[] = $server_id;
                                    }
                                }

                                if ($items[$i]['need_connect']) {
                                    foreach ($items[$i]['servers'] as $k => $server_id) {

                                        if (!$this->Server->userIsConnected($this->User->getKey('pseudo'), $server_id)) {
                                            $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__ITEM_CANT_BUY_NOT_CONNECTED', array('{ITEM_NAME}' => $items[$i]['name'])))));
                                            return;
                                        }

                                    }
                                }

                                if (isset($value['quantity']) && $value['quantity'] > 1) { //si y'en a plusieurs
                                    $duplicate = 1;
                                    while ($duplicate < $value['quantity']) { // on le duplique autant de fois qu'il est acheté

                                        $items[($i + $duplicate)] = $items[$i]; // On l'ajoute à la liste

                                        $duplicate++;
                                    }

                                    $total_price += $items[$i]['price'] * ($value['quantity'] - 1); // On ajoute ce qu'on a dupliqué au prix (on enlève 1 à la quantity parce qu'on la déjà fais une fois)

                                    $i = $i + $duplicate;
                                } else {
                                    $i++; //Si on continue tranquillement
                                }

                            }

                            unset($findItem);
                        }
                    }

                    // On évite le reste si on a pas d'article
                    if (empty($items)) {
                        $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__BUY_ERROR_EMPTY'))));
                        return;
                    }

                    if ($total_price < 0) {
                        $total_price = 0;
                    }


                    // On va vérifier que l'utilisateur a assez d'argent
                    $this->User->cacheQueries = false;
                    $money = $this->User->find('first', array('conditions' => array('id' => $this->User->getKey('id'))))['User']['money'];
                    $new_sold = $money - $total_price;
                    if ($new_sold >= 0) {

                        // On vas voir si tous les serveurs sont ouverts (ceux necessaires aux articles achetés)
                        if (!empty($servers)) {
                            foreach ($servers as $key => $value) {
                                $servers_online[] = $this->Server->online($value);
                            }
                        } else {
                            $servers_online = array($this->Server->online());
                        }

                        if (!in_array(false, $servers_online)) {

                            // L'event
                            $event = new CakeEvent('onBuy', $this, array('items' => $items, 'total_price' => $total_price, 'user' => $this->User->getAllFromCurrentUser()));
                            $this->getEventManager()->dispatch($event);
                            if ($event->isStopped()) {
                                return $event->result;
                            }

                            // Ajouter au champ used si il a utiliser un voucher
                            if (!empty($voucher_code) && $voucher_code_used) {

                                // On le met en utilisé
                                $this->DiscountVoucher->set_used($this->User->getKey('id'), $voucher_code, $voucher_used_count);

                                // On le met dans l'historique
                                $this->loadModel('Shop.VouchersHistory');
                                $this->VouchersHistory->create();
                                $this->VouchersHistory->set(array(
                                    'code' => $voucher_code,
                                    'user_id' => $this->User->getKey('id'),
                                    'reduction' => $voucher_reduction
                                ));
                                $this->VouchersHistory->save();
                            }

                            // On enlève les crédits à l'utilisateur
                            $this->User->id = $this->User->getKey('id');
                            $save = $this->User->saveField('money', $new_sold);

                            // On lui donne éventuellement skin/cape
                            if ($give_skin) {
                                $this->User->setKey('skin', 1);
                            }
                            if ($give_cape) {
                                $this->User->setKey('cape', 1);
                            }

                            // On prépare l'historique a add
                            $history = array();


                            // Si il y a des commandes à faire
                            $items_broadcasted = array();
                            foreach ($items as $key => $value) {

                                // On l'ajoute à l'historique (préparation)
                                //$this->History->set('BUY_ITEM', 'shop', $value['name']);
                                $history[] = array(
                                    'user_id' => $this->User->getKey('id'),
                                    'item_id' => $value['id']
                                );

                                // On execute les commandes
                                if (empty($value['servers'])) {

                                    if (!in_array($value['id'], $items_broadcasted)) {
                                        $value['commands'] .= '[{+}]' . $value['broadcast'];
                                        $items_broadcasted[] = $value['id'];
                                    }

                                    $this->Server->commands($value['commands']);


                                } else {

                                    foreach ($value['servers'] as $k => $server_id) {

                                        if (!in_array($value['id'], $items_broadcasted)) {
                                            $value['commands'] .= '[{+}]' . $value['broadcast'];
                                            $items_broadcasted[] = $value['id'];
                                        }

                                        $this->Server->commands($value['commands'], $server_id);

                                    }

                                }

                                // On s'occupe des commandes à faire après
                                if ($value['timedCommand'])
                                    $this->Server->scheduleCommands($value['timedCommand_cmd'], $value['timedCommand_time'], $value['servers']);
                            }

                            //On le met dans l'historique
                            $this->ItemsBuyHistory->saveMany($history);

                            $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('SHOP__BUY_SUCCESS'))));

                        } else {
                            $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SERVER__MUST_BE_ON'))));
                        }


                    } else {
                        $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__BUY_ERROR_NO_ENOUGH_MONEY'))));
                    }

                } else {
                    $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__BUY_ERROR_EMPTY'))));
                }

            } else {
                $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_MUST_BE_LOGGED'))));
            }

        } else {
            throw new InternalErrorException('Not ajax');
        }
    }


    /*
    * ======== Page principale du panel admin ===========
    */
    public function admin_index()
    {
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {

            $this->set('title_for_layout', $this->Lang->get('SHOP__TITLE'));
            $this->layout = 'admin';

            $this->loadModel('Shop.Item');
            $search_items = $this->Item->find('all');
            $items = array();
            foreach ($search_items as $key => $value) {
                $items[$value['Item']['id']] = $value['Item']['name'];
            }

            $this->loadModel('Shop.Category');
            $search_categories = $this->Category->find('all');
            foreach ($search_categories as $v) {
                $categories[$v['Category']['id']]['name'] = $v['Category']['name'];
            }

            $this->loadModel('Shop.ItemsConfig');
            $findConfig = $this->ItemsConfig->find('first');
            $config = (!empty($findConfig)) ? $findConfig['ItemsConfig'] : array();

            $this->set(compact('categories', 'search_categories', 'search_items', 'config', 'items'));

        } else {
            $this->redirect('/');
        }
    }

    public function admin_get_histories_buy()
    {
        if ($this->isConnected && $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {

            $this->loadModel('Shop.Item');

            $this->autoRender = false;
            $this->response->type('json');

            $this->DataTable = $this->Components->load('DataTable');
            $this->modelClass = 'ItemsBuyHistory';
            $this->DataTable->initialize($this);
            $this->paginate = array(
                'fields' => array('ItemsBuyHistory.created', 'Item.name', 'User.pseudo'),
                'order' => 'ItemsBuyHistory.id DESC',
                'recursive' => 1
            );
            $this->DataTable->mDataProp = true;

            $response = $this->DataTable->getResponse();

            $this->response->body(json_encode($response));

        } else {
            throw new ForbiddenException();
        }
    }


    /*
    * ======== Page principale du panel admin ===========
    */
    public function admin_config_items()
    {
        $this->autoRender = false;
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {

            if ($this->request->is('ajax')) {

                $this->loadModel('Shop.ItemsConfig');

                $ItemsConfig = $this->ItemsConfig->find('first');
                if (empty($ItemsConfig)) {
                    $this->ItemsConfig->create();
                } else {
                    $this->ItemsConfig->read(null, 1);
                }
                $this->ItemsConfig->set($this->request->data);
                $this->ItemsConfig->save();

                $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('SHOP__CONFIG_SAVE_SUCCESS'))));

            } else {
                throw new ForbiddenException();
            }

        } else {
            throw new ForbiddenException();
        }
    }


    /*
    * ======== Modification d'un article (affichage de la page) ===========
    */

    public function admin_edit($id = false)
    {
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {
            if ($id != false) {

                $this->set('title_for_layout', $this->Lang->get('SHOP__ITEM_EDIT'));
                $this->layout = 'admin';
                $this->loadModel('Shop.Item');
                $item = $this->Item->find('all', array('conditions' => array('id' => $id)));
                if (!empty($item)) {
                    $item = $item[0]['Item'];
                    $this->loadModel('Shop.Category');
                    $item['category'] = $this->Category->find('all', array('conditions' => array('id' => $item['category'])));
                    $item['category'] = $item['category'][0]['Category']['name'];

                    $search_categories = $this->Category->find('all', array('fields' => 'name'));
                    $categories = array();
                    foreach ($search_categories as $v) {
                        if ($v['Category']['name'] != $item['category']) {
                            $categories[$v['Category']['name']] = $v['Category']['name'];
                        }
                    }
                    $this->set(compact('categories'));

                    $search_items = $this->Item->find('all', array('fields' => array('name', 'id')));
                    $items_available = array();
                    foreach ($search_items as $v) {
                        $items_available[$v['Item']['id']] = $v['Item']['name'];
                    }
                    $this->set(compact('items_available'));

                    $this->loadModel('Server');

                    $servers = $this->Server->findSelectableServers(true);
                    $this->set(compact('servers'));

                    $selected_server = array();
                    if (!empty($item['servers'])) {
                        $item['servers'] = unserialize($item['servers']);
                        foreach ($item['servers'] as $key => $value)
                            if (isset($servers[$value]))
                                $selected_server[] = $value;
                    }
                    $this->set(compact('selected_server'));

                    $commands = $item['commands'];
                    $commands = explode('[{+}]', $commands);
                    unset($item['commands']);
                    $item['commands'] = $commands;

                    $item['prerequisites'] = unserialize($item['prerequisites']);
                    if (is_bool($item['prerequisites'])) {
                        $item['prerequisites'] = array();
                    }
                    $item['reductional_items'] = unserialize($item['reductional_items']);
                    if (is_bool($item['reductional_items'])) {
                        $item['reductional_items'] = array();
                    }

                    if (isset($item['wait_time']) && !empty($item['wait_time'])) {
                        $wait_time = explode(' ', $item['wait_time']);
                        if (is_array($wait_time) && count($wait_time) == 2) {
                            $item['wait_time'] = array();
                            $item['wait_time']['time'] = $wait_time[0];
                            $item['wait_time']['type'] = $wait_time[1];
                        }
                    }

                    $this->set(compact('item'));

                } else {
                    $this->Session->setFlash($this->Lang->get('UNKNONW_ID'), 'default.error');
                    $this->redirect(array('controller' => 'news', 'action' => 'index', 'admin' => true));
                }
            } else {
                $this->redirect(array('controller' => 'news', 'action' => 'index', 'admin' => true));
            }
        } else {
            $this->redirect('/');
        }
    }


    /*
    * ======== Modification de l'article (traitement AJAX) ===========
    */

    public function admin_edit_ajax()
    {
        $this->autoRender = false;
        $this->response->type('json');
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {
            if ($this->request->is('post')) {
                if (empty($this->request->data['category'])) {
                    $this->request->data['category'] = $this->request->data['category_default'];
                }
                if (!empty($this->request->data['id']) AND !empty($this->request->data['name']) AND !empty($this->request->data['description']) AND !empty($this->request->data['category']) AND strlen($this->request->data['price']) > 0 AND !empty($this->request->data['servers']) AND !empty($this->request->data['commands']) AND !empty($this->request->data['timedCommand'])) {
                    $this->loadModel('Shop.Category');
                    $this->request->data['category'] = $this->Category->find('all', array('conditions' => array('name' => $this->request->data['category'])));
                    $this->request->data['category'] = $this->request->data['category'][0]['Category']['id'];
                    $this->request->data['timedCommand'] = ($this->request->data['timedCommand'] == 'true') ? 1 : 0;
                    if (!$this->request->data['timedCommand']) {
                        $this->request->data['timedCommand_cmd'] = NULL;
                        $this->request->data['timedCommand_time'] = NULL;
                    }

                    $commands = implode('[{+}]', $this->request->data['commands']);

                    $this->request->data['commands'] = $commands;
                    $event = new CakeEvent('beforeEditItem', $this, array('data' => $this->request->data, 'user' => $this->User->getAllFromCurrentUser()));
                    $this->getEventManager()->dispatch($event);
                    if ($event->isStopped()) {
                        return $event->result;
                    }

                    $prerequisites = (isset($this->request->data['prerequisites'])) ? serialize($this->request->data['prerequisites']) : NULL;
                    $reductional_items = (isset($this->request->data['reductional_items']) && $this->request->data['reductional_items_checkbox']) ? serialize($this->request->data['reductional_items']) : NULL;

                    $wait_time = implode(' ', $this->request->data['wait_time']);

                    $this->loadModel('Shop.Item');
                    $this->Item->read(null, $this->request->data['id']);
                    $this->Item->set(array(
                        'name' => $this->request->data['name'],
                        'description' => $this->request->data['description'],
                        'category' => $this->request->data['category'],
                        'price' => $this->request->data['price'],
                        'servers' => serialize($this->request->data['servers']),
                        'commands' => $commands,
                        'img_url' => $this->request->data['img_url'],
                        'timedCommand' => $this->request->data['timedCommand'],
                        'timedCommand_cmd' => $this->request->data['timedCommand_cmd'],
                        'timedCommand_time' => $this->request->data['timedCommand_time'],
                        'display_server' => $this->request->data['display_server'],
                        'need_connect' => $this->request->data['need_connect'],
                        'display' => $this->request->data['display'],
                        'multiple_buy' => $this->request->data['multiple_buy'],
                        'broadcast_global' => $this->request->data['broadcast_global'],
                        'cart' => $this->request->data['cart'],
                        'prerequisites_type' => $this->request->data['prerequisites_type'],
                        'prerequisites' => $prerequisites,
                        'reductional_items' => $reductional_items,
                        'give_skin' => $this->request->data['give_skin'],
                        'give_cape' => $this->request->data['give_cape'],
                        'buy_limit' => $this->request->data['buy_limit'],
                        'wait_time' => $wait_time
                    ));
                    $this->Item->save();
                    $this->Session->setFlash($this->Lang->get('SHOP__ITEM_EDIT_SUCCESS'), 'default.success');
                    $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('SHOP__ITEM_EDIT_SUCCESS'))));
                } else {
                    $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS'))));
                }
            } else {
                $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST'))));
            }
        } else {
            throw new ForbiddenException();
        }
    }


    /*
    * ======== Ajout d'un article (affichage) ===========
    */

    public function admin_add_item()
    {
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {

            $this->set('title_for_layout', $this->Lang->get('SHOP__ITEM_ADD'));
            $this->layout = 'admin';
            $this->loadModel('Shop.Category');
            $search_categories = $this->Category->find('all', array('fields' => 'name'));
            $categories = array();
            foreach ($search_categories as $v) {
                $categories[$v['Category']['name']] = $v['Category']['name'];
            }
            $this->set(compact('categories'));

            $this->loadModel('Shop.Item');
            $search_items = $this->Item->find('all', array('fields' => array('name', 'id')));
            $items_available = array();
            foreach ($search_items as $v) {
                $items_available[$v['Item']['id']] = $v['Item']['name'];
            }
            $this->set(compact('items_available'));

            $this->loadModel('Server');
            $servers = $this->Server->findSelectableServers(true);
            $this->set(compact('servers'));

        } else {
            $this->redirect('/');
        }
    }


    /*
    * ======== Ajout d'un article (Traitement AJAX) ===========
    */

    public function admin_add_item_ajax()
    {
        $this->autoRender = false;
        $this->response->type('json');
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {
            if ($this->request->is('post')) {

                if (!empty($this->request->data['name']) AND !empty($this->request->data['description']) AND !empty($this->request->data['category']) AND strlen($this->request->data['price']) > 0 AND !empty($this->request->data['servers']) AND !empty($this->request->data['commands']) AND !empty($this->request->data['timedCommand'])) {
                    $this->loadModel('Shop.Category');
                    $this->request->data['category'] = $this->Category->find('all', array('conditions' => array('name' => $this->request->data['category'])));
                    $this->request->data['category'] = $this->request->data['category'][0]['Category']['id'];
                    $this->request->data['timedCommand'] = ($this->request->data['timedCommand'] == 'true') ? 1 : 0;
                    if (!$this->request->data['timedCommand']) {
                        $this->request->data['timedCommand_cmd'] = NULL;
                        $this->request->data['timedCommand_time'] = NULL;
                    }

                    $commands = implode('[{+}]', $this->request->data['commands']);

                    $this->request->data['commands'] = $commands;
                    $event = new CakeEvent('beforeAddItem', $this, array('data' => $this->request->data, 'user' => $this->User->getAllFromCurrentUser()));
                    $this->getEventManager()->dispatch($event);
                    if ($event->isStopped()) {
                        return $event->result;
                    }

                    $prerequisites = (isset($this->request->data['prerequisites'])) ? serialize($this->request->data['prerequisites']) : NULL;
                    $reductional_items = (isset($this->request->data['reductional_items']) && $this->request->data['reductional_items_checkbox']) ? serialize($this->request->data['reductional_items']) : NULL;

                    $wait_time = implode(' ', $this->request->data['wait_time']);

                    $this->loadModel('Shop.Item');
                    $this->Item->read(null, null);
                    $this->Item->set(array(
                        'name' => $this->request->data['name'],
                        'description' => $this->request->data['description'],
                        'category' => $this->request->data['category'],
                        'price' => $this->request->data['price'],
                        'servers' => serialize($this->request->data['servers']),
                        'commands' => $commands,
                        'img_url' => $this->request->data['img_url'],
                        'timedCommand' => $this->request->data['timedCommand'],
                        'timedCommand_cmd' => $this->request->data['timedCommand_cmd'],
                        'timedCommand_time' => $this->request->data['timedCommand_time'],
                        'display_server' => $this->request->data['display_server'],
                        'need_connect' => $this->request->data['need_connect'],
                        'display' => $this->request->data['display'],
                        'multiple_buy' => $this->request->data['multiple_buy'],
                        'broadcast_global' => $this->request->data['broadcast_global'],
                        'cart' => $this->request->data['broadcast_global'],
                        'prerequisites_type' => $this->request->data['prerequisites_type'],
                        'prerequisites' => $prerequisites,
                        'reductional_items' => $reductional_items,
                        'give_skin' => $this->request->data['give_skin'],
                        'give_cape' => $this->request->data['give_cape'],
                        'buy_limit' => $this->request->data['buy_limit'],
                        'wait_time' => $wait_time
                    ));
                    $this->Item->save();
                    $this->History->set('ADD_ITEM', 'shop');
                    $this->Session->setFlash($this->Lang->get('SHOP__ITEM_ADD_SUCCESS'), 'default.success');
                    $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('SHOP__ITEM_ADD_SUCCESS'))));
                } else {
                    $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS'))));
                }
            } else {
                $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST'))));
            }
        } else {
            throw new ForbiddenException();
        }
    }


    /*
    * ======== Ajout d'une catégorie (affichage & traitement POST) ===========
    */

    public function admin_add_category()
    {
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {

            $this->layout = 'admin';
            $this->set('title_for_layout', $this->Lang->get('SHOP__CATEGORY_ADD'));
            if ($this->request->is('post')) {
                if (!empty($this->request->data['name'])) {
                    $this->loadModel('Shop.Category');

                    $event = new CakeEvent('beforeAddCategory', $this, array('category' => $this->request->data['name'], 'user' => $this->User->getAllFromCurrentUser()));
                    $this->getEventManager()->dispatch($event);
                    if ($event->isStopped()) {
                        return $event->result;
                    }

                    $this->Category->read(null, null);
                    $this->Category->set(array(
                        'name' => $this->request->data['name'],
                    ));
                    $this->History->set('ADD_CATEGORY', 'shop');
                    $this->Category->save();
                    $this->Session->setFlash($this->Lang->get('SHOP__CATEGORY_ADD_SUCCESS'), 'default.success');
                    $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                } else {
                    $this->Session->setFlash($this->Lang->get('ERROR__FILL_ALL_FIELDS'), 'default.error');
                }
            }
        } else {
            $this->redirect('/');
        }
    }

    /*
    * ======== Suppression d'une catégorie/article/paypal/starpass (traitement) ===========
    */

    public function admin_delete($type = false, $id = false)
    {
        $this->autoRender = false;
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_ITEMS')) {
            if ($type != false AND $id != false) {
                if ($type == "item") {
                    $this->loadModel('Shop.Item');
                    $find = $this->Item->find('all', array('conditions' => array('id' => $id)));
                    if (!empty($find)) {

                        $event = new CakeEvent('beforeDeleteItem', $this, array('item_id' => $id, 'user' => $this->User->getAllFromCurrentUser()));
                        $this->getEventManager()->dispatch($event);
                        if ($event->isStopped()) {
                            return $event->result;
                        }

                        $this->Item->delete($id);
                        $this->History->set('DELETE_ITEM', 'shop');
                        $this->Session->setFlash($this->Lang->get('SHOP__ITEM_DELETE_SUCCESS'), 'default.success');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    } else {
                        $this->Session->setFlash($this->Lang->get('UNKNONW_ID'), 'default.error');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    }
                } elseif ($type == "category") {
                    $this->loadModel('Shop.Category');
                    $find = $this->Category->find('all', array('conditions' => array('id' => $id)));
                    if (!empty($find)) {

                        $event = new CakeEvent('beforeDeleteCategory', $this, array('category_id' => $id, 'user' => $this->User->getAllFromCurrentUser()));
                        $this->getEventManager()->dispatch($event);
                        if ($event->isStopped()) {
                            return $event->result;
                        }

                        $this->Category->delete($id);
                        $this->History->set('DELETE_CATEGORY', 'shop');
                        $this->Session->setFlash($this->Lang->get('SHOP__CATEGORY_DELETE_SUCCESS'), 'default.success');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    } else {
                        $this->Session->setFlash($this->Lang->get('UNKNONW_ID'), 'default.error');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    }
                } elseif ($type == "paypal") {
                    $this->loadModel('Shop.Paypal');
                    $find = $this->Paypal->find('all', array('conditions' => array('id' => $id)));
                    if (!empty($find)) {

                        $event = new CakeEvent('beforeDeletePaypalOffer', $this, array('offer_id' => $id, 'user' => $this->User->getAllFromCurrentUser()));
                        $this->getEventManager()->dispatch($event);
                        if ($event->isStopped()) {
                            return $event->result;
                        }

                        $this->Paypal->delete($id);
                        $this->History->set('DELETE_PAYPAL_OFFER', 'shop');
                        $this->Session->setFlash($this->Lang->get('SHOP__PAYPAL_OFFER_DELETE_SUCCESS'), 'default.success');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    } else {
                        $this->Session->setFlash($this->Lang->get('UNKNONW_ID'), 'default.error');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    }
                } elseif ($type == "starpass") {
                    $this->loadModel('Shop.Starpass');
                    $find = $this->Starpass->find('all', array('conditions' => array('id' => $id)));
                    if (!empty($find)) {

                        $event = new CakeEvent('beforeDeleteStarpassOffer', $this, array('offer_id' => $id, 'user' => $this->User->getAllFromCurrentUser()));
                        $this->getEventManager()->dispatch($event);
                        if ($event->isStopped()) {
                            return $event->result;
                        }

                        $this->Starpass->delete($id);
                        $this->History->set('DELETE_STARPASS_OFFER', 'shop');
                        $this->Session->setFlash($this->Lang->get('SHOP__STARPASS_OFFER_DELETE_SUCCESS'), 'default.success');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    } else {
                        $this->Session->setFlash($this->Lang->get('UNKNONW_ID'), 'default.error');
                        $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
                    }
                }
            } else {
                $this->redirect(array('controller' => 'shop', 'action' => 'index', 'admin' => true));
            }
        } else {
            $this->redirect('/');
        }
    }


    /*
    * ======== Page principale pour les promos ===========
    */


    public function admin_vouchers()
    {
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_VOUCHERS')) {

            $this->set('title_for_layout', $this->Lang->get('SHOP__VOUCHERS_MANAGE'));
            $this->layout = 'admin';

            $this->loadModel('Shop.Voucher');
            $vouchers = $this->Voucher->find('all');

            $this->loadModel('Shop.VouchersHistory');
            $vouchers_histories = $this->VouchersHistory->find('all', array('order' => 'id DESC'));

            $usersToFind = array();
            foreach ($vouchers_histories as $key => $value) {
                $usersToFind[] = $value['VouchersHistory']['user_id'];
            }

            $usersByID = array();

            $findUsers = $this->User->find('all', array('conditions' => array('id' => $usersToFind)));
            foreach ($findUsers as $key => $value) {
                $usersByID[$value['User']['id']] = $value['User']['pseudo'];
            }

            $itemsByID = array();

            $this->loadModel('Shop.Item');
            $findItems = $this->Item->find('all');
            foreach ($findItems as $key => $value) {
                $itemsByID[$value['Item']['id']] = $value['Item']['name'];
            }

            $this->set(compact('vouchers', 'vouchers_histories', 'usersByID', 'itemsByID'));

        } else {
            throw new ForbiddenException();
        }
    }


    /*
    * ======== Ajout d'un code promotionnel (affichage) ===========
    */

    public function admin_add_voucher()
    {
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_VOUCHERS')) {

            $this->set('title_for_layout', $this->Lang->get('SHOP__VOUCHER_ADD'));
            $this->layout = 'admin';

            $this->loadModel('Shop.Category');
            $search_categories = $this->Category->find('all', array('fields' => array('name', 'id')));
            foreach ($search_categories as $v) {
                $categories[$v['Category']['id']] = $v['Category']['name'];
            }
            $this->set(compact('categories'));
            $this->loadModel('Shop.Item');
            $search_items = $this->Item->find('all', array('fields' => array('name', 'id')));
            foreach ($search_items as $v) {
                $items[$v['Item']['id']] = $v['Item']['name'];
            }
            $this->set(compact('items'));

        } else {
            $this->redirect('/');
        }
    }


    /*
    * ======== Ajout d'un code promotionnel (traitement AJAX) ===========
    */

    public function admin_add_voucher_ajax()
    {
        $this->autoRender = false;
        $this->response->type('json');
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_VOUCHERS')) {
            if ($this->request->is('post')) {
                if (!empty($this->request->data['code']) AND !empty($this->request->data['effective_on']) AND !empty($this->request->data['type']) AND !empty($this->request->data['reduction']) AND !empty($this->request->data['end_date'])) {
                    if (preg_match('/^[a-zA-Z0-9#]{0,20}$/', $this->request->data['code'])) {

                        if ($this->request->data['effective_on'] == "categories") {
                            $effective_on_value = array('type' => 'categories', 'value' => $this->request->data['effective_on_categorie']);
                        }
                        if ($this->request->data['effective_on'] == "items") {
                            $effective_on_value = array('type' => 'items', 'value' => $this->request->data['effective_on_item']);
                        }
                        if ($this->request->data['effective_on'] == "all") {
                            $effective_on_value = array('type' => 'all');
                        }

                        $this->request->data['effective_on'] = $effective_on_value;
                        $event = new CakeEvent('beforeAddVoucher', $this, array('data' => $this->request->data, 'user' => $this->User->getAllFromCurrentUser()));
                        $this->getEventManager()->dispatch($event);
                        if ($event->isStopped()) {
                            return $event->result;
                        }

                        if ($this->request->data['affich']) {
                            $this->loadModel('Notification');
                            $this->Notification->setToAll($this->Lang->get('NOTIFICATION__NEW_VOUCHER'));
                        }

                        $this->loadModel('Shop.Voucher');
                        $this->Voucher->read(null, null);
                        $this->Voucher->set(array(
                            'code' => $this->request->data['code'],
                            'effective_on' => serialize($effective_on_value),
                            'type' => intval($this->request->data['type']),
                            'reduction' => $this->request->data['reduction'],
                            'limit_per_user' => $this->request->data['limit_per_user'],
                            'start_date' => $this->request->data['start_date'],
                            'end_date' => $this->request->data['end_date'],
                            'affich' => $this->request->data['affich'],
                        ));
                        $this->Voucher->save();
                        $this->History->set('ADD_VOUCHER', 'shop');
                        $this->Session->setFlash($this->Lang->get('SHOP__VOUCHER_ADD_SUCCESS'), 'default.success');
                        $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('SHOP__VOUCHER_ADD_SUCCESS'))));

                    } else {
                        $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__VOUCHER_ADD_ERROR_CODE_INVALID'))));
                    }
                } else {
                    $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS'))));
                }
            } else {
                $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST'))));
            }
        } else {
            throw new ForbiddenException();
        }
    }


    /*
    * ======== Suppression d'un code promotionnel (traitement POST) ===========
    */

    public function admin_delete_voucher($id = false)
    {
        $this->autoRender = false;
        if ($this->isConnected AND $this->Permissions->can('SHOP__ADMIN_MANAGE_VOUCHERS')) {
            if ($id != false) {

                $event = new CakeEvent('beforeDeleteVoucher', $this, array('voucher_id' => $id, 'user' => $this->User->getAllFromCurrentUser()));
                $this->getEventManager()->dispatch($event);
                if ($event->isStopped()) {
                    return $event->result;
                }

                $this->loadModel('Shop.Voucher');
                $this->Voucher->delete($id);
                $this->History->set('DELETE_VOUCHER', 'shop');
                $this->Session->setFlash($this->Lang->get('SHOP__VOUCHER_DELETE_SUCCESS'), 'default.success');
                $this->redirect(array('controller' => 'shop', 'action' => 'vouchers', 'admin' => true));
            } else {
                $this->redirect(array('controller' => 'shop', 'action' => 'vouchers', 'admin' => true));
            }
        } else {
            $this->redirect('/');
        }
    }

}
