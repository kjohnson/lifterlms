<?php
if (!defined('ABSPATH')) exit;

/**
 * Voucher Class
 *
 * @author codeBOX
 * @project lifterLMS
 */
class LLMS_Voucher
{
    protected $id;

    protected static $codes_table_name = 'llms_vouchers_codes';
    protected static $redemptions_table = 'llms_voucher_code_redemptions';
    protected static $product2voucher_table = 'llms_product2voucher';

    protected function get_codes_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . self::$codes_table_name;
    }

    protected function get_redemptions_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . self::$redemptions_table;
    }

    protected function get_product2voucher_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . self::$product2voucher_table;
    }

    public function __construct($id = null)
    {
        $this->id = $id;
    }

    // Get single voucher code
    public function get_voucher_by_voucher_id()
    {
        global $wpdb;

        $table = $this->get_codes_table_name();


        $query = "SELECT * FROM $table WHERE `voucher_id` = $this->id AND `is_deleted` = 0 LIMIT 1";
        return $wpdb->get_row($query);
    }

    // Get single voucher code by code
    public function get_voucher_by_code($code)
    {
        global $wpdb;

        $table = $this->get_codes_table_name();
        $redeemed_table = $this->get_redemptions_table_name();

        $query = "SELECT c.*, count(r.id) as used
                  FROM $table as c
                  LEFT JOIN $redeemed_table as r
                  ON c.`id` = r.`code_id`
                  WHERE `code` = '$code' AND `is_deleted` = 0
                  GROUP BY c.id
                  LIMIT 1";
        return $wpdb->get_row($query);
    }

    public function get_voucher_codes($format = 'OBJECT')
    {
        global $wpdb;

        $table = $this->get_codes_table_name();
        $redeemed_table = $this->get_redemptions_table_name();

        $query = "SELECT c.*, count(r.id) as used
                  FROM $table as c
                  LEFT JOIN $redeemed_table as r
                  ON c.`id` = r.`code_id`
                  WHERE `voucher_id` = $this->id AND `is_deleted` = 0
                  GROUP BY c.id";
        return $wpdb->get_results($query, $format);
    }

    public function get_voucher_code_by_code_id($code_id)
    {
        global $wpdb;

        $table = $this->get_codes_table_name();

        $query = "SELECT * FROM $table WHERE `id` = $code_id AND `is_deleted` = 0 LIMIT 1";
        return $wpdb->get_row($query);
    }

    public function save_voucher_code($data)
    {
        global $wpdb;

        $data['voucher_id'] = $this->id;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $wpdb->insert($this->get_codes_table_name(), $data);
    }

    public function update_voucher_code($data)
    {
        global $wpdb;

        $data['updated_at'] = date('Y-m-d H:i:s');

        $where = array('id' => $data['id']);
        unset($data['id']);
        return $wpdb->update($this->get_codes_table_name(), $data, $where);
    }

    public function delete_voucher_code($id)
    {
        global $wpdb;

        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['is_deleted'] = 1;

        $where = array('id' => $id);
        unset($data['id']);
        return $wpdb->update($this->get_codes_table_name(), $data, $where);
    }

    public function check_voucher($code)
    {
        $voucher = $this->get_voucher_by_code($code);

        if (empty($voucher) || $voucher->redemption_count <= $voucher->used) {
            $voucher = false;
        }

        return $voucher;
    }

    public function use_voucher($code, $user_id)
    {
        $voucher = $this->check_voucher($code);

        if ($voucher) {
            $this->id = $voucher->voucher_id;

            // use voucher code
            $data = array(
                'user_id' => $user_id,
                'code_id' => $voucher->id
            );

            $this->save_redeemed_code($data);

            // create order for products liked to voucher
            $products = $this->get_products();

            if (!empty($products)) {
                global $wpdb;

                $membership_levels = array();

                foreach ($products as $product) {
                    $order = new LLMS_Order();
                    $order->create($user_id, $product, 'Voucher');

                    if (get_post_type($product) === 'llms_membership') {
                        $membership_levels[] = $product;
                    }

                    // update user postmeta
                    $user_metadatas = array(
                        '_start_date' => 'yes',
                        '_status' => 'Enrolled',
                    );

                    foreach ($user_metadatas as $key => $value) {
                        $wpdb->insert($wpdb->prefix . 'lifterlms_user_postmeta',
                            array(
                                'user_id' => $user_id,
                                'post_id' => $product,
                                'meta_key' => $key,
                                'meta_value' => $value,
                                'updated_date' => current_time('mysql'),
                            )
                        );
                    }
                }

                if (!empty($membership_levels)) {
                    update_user_meta($user_id, '_llms_restricted_levels', $membership_levels);
                }

            }
        }

        return $voucher;
    }

    /**
     * Redeemed Codes
     */
    public function get_redeemed_codes($format = 'OBJECT')
    {
        global $wpdb;

        $table = $this->get_codes_table_name();
        $redeemed_table = $this->get_redemptions_table_name();
        $users_table = $wpdb->prefix . 'users';

//        $query = "SELECT r.`id`, c.`id` as code_id, c.`voucher_id`, c.`code`, c.`redemption_count`, c.`is_deleted`, r.`user_id`, r.`redemption_date`
//                  FROM $table as c
//                  JOIN $redeemed_table as r
//                  ON c.`id` = r.`code_id`
//                  WHERE c.`is_deleted` = 0 AND c.`voucher_id` = $this->id";

        $query = "SELECT r.`id`, c.`id` as code_id, c.`voucher_id`, c.`code`, c.`redemption_count`, r.`user_id`, u.`user_email`, r.`redemption_date`
                  FROM $table as c
                  JOIN $redeemed_table as r
                  ON c.`id` = r.`code_id`
                  JOIN $users_table as u
                  ON r.`user_id` = u.`ID`
                  WHERE c.`is_deleted` = 0 AND c.`voucher_id` = $this->id";

        return $wpdb->get_results($query, $format);
    }

    public function save_redeemed_code($data)
    {
        global $wpdb;

        $data['redemption_date'] = date('Y-m-d H:i:s');

        return $wpdb->insert($this->get_redemptions_table_name(), $data);
    }

    /**
     * Product 2 Voucher
     */

    public function get_products()
    {
        global $wpdb;

        $table = $this->get_product2voucher_table_name();

        $query = "SELECT * FROM $table WHERE `voucher_id` = $this->id";

        $results = $wpdb->get_results($query);

        $products = array();
        if (!empty($results)) {
            foreach ($results as $item) {
                $products[] = intval($item->product_id);
            }
        }

        return $products;
    }

    public function save_product($product_id)
    {
        global $wpdb;

        $data['voucher_id'] = $this->id;
        $data['product_id'] = $product_id;

        return $wpdb->insert($this->get_product2voucher_table_name(), $data);
    }

    public function delete_products()
    {
        global $wpdb;

        return $wpdb->delete($this->get_product2voucher_table_name(), array('voucher_id' => $this->id));
    }
}
