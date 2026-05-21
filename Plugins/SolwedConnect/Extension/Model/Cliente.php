<?php
namespace FacturaScripts\Plugins\SolwedConnect\Extension\Model;

use Closure;
use FacturaScripts\Plugins\SolwedConnect\Lib\MindClient;

class Cliente
{
    public function saveInsert(): Closure
    {
        return function () {
            MindClient::emit('cliente.created', [
                'codcliente' => $this->codcliente,
                'nombre' => $this->nombre,
                'cifnif' => $this->cifnif,
                'email' => $this->email,
            ]);
            return true;
        };
    }
}
