<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

interface AIProvider
{
    public function getName();
    public function complete(array $messages, $timeout);
}
