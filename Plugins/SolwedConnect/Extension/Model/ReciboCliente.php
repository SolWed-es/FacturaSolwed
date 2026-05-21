<?php
namespace FacturaScripts\Plugins\SolwedConnect\Extension\Model;

use Closure;
use FacturaScripts\Plugins\SolwedConnect\Lib\MindClient;

class ReciboCliente
{
    public function saveUpdate(): Closure
    {
        return function () {
            if ($this->pagado === true) {
                MindClient::emit('pago.recibido', [
                    'idrecibo' => $this->idrecibo,
                    'codcliente' => $this->codcliente,
                    'importe' => $this->importe,
                    'fechapago' => $this->fechapago,
                ]);
            }
            return true;
        };
    }
}
