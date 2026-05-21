<?php
namespace FacturaScripts\Plugins\SolwedConnect\Extension\Model;

use Closure;
use FacturaScripts\Plugins\SolwedConnect\Lib\MindClient;

class FacturaCliente
{
    public function saveInsert(): Closure
    {
        return function () {
            MindClient::emit('factura.created', [
                'codigo' => $this->codigo,
                'codcliente' => $this->codcliente,
                'fecha' => $this->fecha,
                'total' => $this->total,
                'pagada' => $this->pagada,
            ]);
            return true;
        };
    }
}
