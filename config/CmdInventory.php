<?php
namespace config;

class CmdInventory extends Structure
{

    public $command = CMD::INVERTORY;

    public $data = [];

    public $data_device_id = 0;

    public $data_transaction_number = 0;

    public $hiddens = [
        'data_device_id',
        'data_transaction_number'
    ];

    public function __construct($device_id, $transaction_number)
    {
        $this->data_device_id = $device_id;
        $this->data_transaction_number = $transaction_number;
        parent::__construct([
            'command' => 'string',
            'data' => 'array'
        ]);
    }

    /**
     * 订单ID命令
     * 
     * @param int $device_id            
     * @param string $transaction_number            
     */
    public static function cmd($device_id, $transaction_number)
    {
        return new self($device_id, $transaction_number);
    }
}