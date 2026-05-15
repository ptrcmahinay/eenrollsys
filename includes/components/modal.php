<?php

function render_modal(string $id, string $title, string $body, bool $wide = false): string
{
    $boxClass = 'modal-box' . ($wide ? ' wide' : '');
    return "
    <div id='$id' class='modal'>
        <div class='$boxClass'>
            <div class='modal-header'>
                <h3>$title</h3>
                <button class='modal-close' data-close='$id'>×</button>
            </div>
            <div class='modal-body'>
                $body
            </div>
        </div>
    </div>
    ";
}
