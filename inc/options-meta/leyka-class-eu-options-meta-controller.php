<?php if( !defined('WPINC') ) die;

class Leyka_Eu_Options_Meta_Controller extends Leyka_Options_Meta_Controller {

    protected static $_instance;

    protected function _get_meta_org() { // Keywords: org_
        return parent::_get_meta_org() + array(
                'org_bank_iban' => array(
                    'type' => 'text',
                    'title' => __('The organization bank account IBAN', 'leyka'),
                    'comment' => '',
                    'required' => true,
                    'placeholder' => sprintf(__('E.g., %s', 'leyka'), 'BY20 OLMP 3135 0000 0010 0000 0933')
                ),
                'org_erdpou' => array(
                    'type' => 'text',
                    'title' => __('The organization ERDPOU number', 'leyka'),
                    'description' => __('Enter the organization ERDPOU number.', 'leyka'),
                    'required' => true,
                    'placeholder' => sprintf(__('E.g., %s', 'leyka'), '32852561'),
                    'mask' => "'mask': '9{8}'",
                ),
                'org_bank_mfo' => array(
                    'type' => 'text',
                    'title' => __('The organization bank MFO number', 'leyka'),
                    'comment' => '',
                    'required' => true,
                    'placeholder' => sprintf(__('E.g., %s', 'leyka'), '309412'),
                    'mask' => "'mask': '9{6}'",
                ),
            );
    }

}