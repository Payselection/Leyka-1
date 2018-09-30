<?php if( !defined('WPINC') ) die;

/** Custom field group for the Yandex Kassa general info step. */

/** @var $this Leyka_Custom_Setting_Block A block for which the template is used. */

if($this->field_data['option_id']) {
    $option_value = leyka_options()->opt($this->field_data['option_id']);
}
else {
    $option_value = '';
}

?>

<div class="enum-separated-block">
    <div class="block-separator"><div></div></div>
    
    <?php if(!empty($this->field_data['caption'])):?>
        <div class="caption"><?php echo $this->field_data['caption']?></div>
    <?php endif?>
    
    <?php if(!empty($this->field_data['value_url'])):?>
    
        <div class="body value">
            <a target="_blank" href="<?php echo $this->field_data['value_url']?>"><?php echo $this->field_data['value_url']?></a>
        </div>
    
    <?php elseif(!empty($this->field_data['value_text'])):?>
    
        <div class="body value">
            <b><?php echo $this->field_data['value_text']?></b>
        </div>
    
    <?php elseif(!empty($this->field_data['option_id'])):?>
    
        <?php if(!empty($this->field_data['show_text_if_set']) && $option_value):?>
        
            <div class="body value">
                <b><?php echo $option_value?></b>
            </div>
            
        <?php else: ?>
    
            <?php leyka_render_text_field($this->field_data['option_id'], array('title' => $this->field_data['option_title'], 'placeholder' => $this->field_data['option_placeholder'], 'value' => $option_value))?>
            
        <?php endif?>
        
    <?php elseif(!empty($this->field_data['screenshot'])):?>
    
        <?php show_wizard_captioned_screenshot($this->field_data['screenshot'], !empty($this->field_data['screenshot_full']) ? $this->field_data['screenshot_full'] : null)?>
        
    <?php endif?>
</div>
