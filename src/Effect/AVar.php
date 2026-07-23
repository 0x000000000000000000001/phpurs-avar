<?php

class AVarMutableQueue {
    public $head = null;
    public $last = null;
    public $size = 0;
}

class AVarMutableCell {
    public $queue;
    public $value;
    public $next = null;
    public $prev = null;
    
    public function __construct($queue, $value) {
        $this->queue = queue;
        $this->value = value;
    }
}

class AVarEMPTY {}

class AVar {
    public $draining = false;
    public $error = null;
    public $value;
    public $takes;
    public $reads;
    public $puts;

    public function __construct($value) {
        $this->value = value;
        $this->takes = new AVarMutableQueue();
        $this->reads = new AVarMutableQueue();
        $this->puts  = new AVarMutableQueue();
    }
}

$AVar_EMPTY = new AVarEMPTY();

$runEff = function($eff) {
    try {
        $eff();
    } catch (\Throwable $error) {
        // PHP has no setTimeout(..., 0) out of the box, we just throw or defer.
        // For CLI, we can just throw. Wait, throwing here breaks the event loop!
        // We will throw because we have no microtask queue.
        throw $error;
    }
};

$putLast = function($queue, $value) {
    $cell = new AVarMutableCell($queue, $value);
    switch ($queue->size) {
        case 0:
            $queue->head = $cell;
            break;
        case 1:
            $cell->prev = $queue->head;
            $queue->head->next = $cell;
            $queue->last = $cell;
            break;
        default:
            $cell->prev = $queue->last;
            $queue->last->next = $cell;
            $queue->last = $cell;
            break;
    }
    $queue->size++;
    return $cell;
};

$takeLast = function($queue) {
    switch ($queue->size) {
        case 0:
            return null;
        case 1:
            $cell = $queue->head;
            $queue->head = null;
            break;
        case 2:
            $cell = $queue->last;
            $queue->head->next = null;
            $queue->last = null;
            break;
        default:
            $cell = $queue->last;
            $queue->last = $cell->prev;
            $queue->last->next = null;
            break;
    }
    $cell->prev = null;
    $cell->queue = null;
    $queue->size--;
    return $cell->value;
};

$takeHead = function($queue) {
    switch ($queue->size) {
        case 0:
            return null;
        case 1:
            $cell = $queue->head;
            $queue->head = null;
            break;
        case 2:
            $cell = $queue->head;
            $queue->last->prev = null;
            $queue->head = $queue->last;
            $queue->last = null;
            break;
        default:
            $cell = $queue->head;
            $queue->head = $cell->next;
            $queue->head->prev = null;
            break;
    }
    $cell->next = null;
    $cell->queue = null;
    $queue->size--;
    return $cell->value;
};

$deleteCell = function($cell) use ($takeLast, $takeHead) {
    if ($cell->queue === null) {
        return;
    }
    if ($cell->queue->last === $cell) {
        $takeLast($cell->queue);
        return;
    }
    if ($cell->queue->head === $cell) {
        $takeHead($cell->queue);
        return;
    }
    if ($cell->prev) {
        $cell->prev->next = $cell->next;
    }
    if ($cell->next) {
        $cell->next->prev = $cell->prev;
    }
    $cell->queue->size--;
    $cell->queue = null;
    $cell->value = null;
    $cell->next  = null;
    $cell->prev  = null;
};

$drainVar = function($util, $avar) use ($AVar_EMPTY, $takeHead, $runEff) {
    if ($avar->draining) {
        return;
    }

    $ps = $avar->puts;
    $ts = $avar->takes;
    $rs = $avar->reads;

    $avar->draining = true;

    while (true) {
        $p = null;
        $r = null;
        $t = null;
        $value = $avar->value;
        $rsize = $rs->size;

        if ($avar->error !== null) {
            $value = $util->left($avar->error);
            while ($p = $takeHead($ps)) {
                $runEff($p->value->cb($value));
            }
            while ($r = $takeHead($rs)) {
                $runEff($r($value));
            }
            while ($t = $takeHead($ts)) {
                $runEff($t($value));
            }
            break;
        }

        if ($value === $AVar_EMPTY && ($p = $takeHead($ps))) {
            $avar->value = $value = $p->value->value;
        }

        if ($value !== $AVar_EMPTY) {
            $t = $takeHead($ts);
            while ($rsize-- && ($r = $takeHead($rs))) {
                $runEff($r($util->right($value)));
            }
            if ($t !== null) {
                $avar->value = $AVar_EMPTY;
                $runEff($t($util->right($value)));
            }
        }

        if ($p !== null) {
            $runEff($p->value->cb($util->right(null)));
        }

        if (($avar->value === $AVar_EMPTY && $ps->size === 0) || ($avar->value !== $AVar_EMPTY && $ts->size === 0)) {
            break;
        }
    }
    $avar->draining = false;
};


$exports['empty'] = function () use ($AVar_EMPTY) {
    return new AVar($AVar_EMPTY);
};

$exports['_newVar'] = function ($value) {
    return function () use ($value) {
        return new AVar($value);
    };
};

$exports['_killVar'] = function ($util, $error, $avar) use ($AVar_EMPTY, $drainVar) {
    return function () use ($util, $error, $avar, $AVar_EMPTY, $drainVar) {
        if ($avar->error === null) {
            $avar->error = $error;
            $avar->value = $AVar_EMPTY;
            $drainVar($util, $avar);
        }
    };
};

$exports['_putVar'] = function ($util, $value, $avar, $cb) use ($putLast, $drainVar, $deleteCell) {
    return function () use ($util, $value, $avar, $cb, $putLast, $drainVar, $deleteCell) {
        $cell = $putLast($avar->puts, (object)['cb' => $cb, 'value' => $value]);
        $drainVar($util, $avar);
        return function () use ($cell, $deleteCell) {
            $deleteCell($cell);
        };
    };
};

$exports['_takeVar'] = function ($util, $avar, $cb) use ($putLast, $drainVar, $deleteCell) {
    return function () use ($util, $avar, $cb, $putLast, $drainVar, $deleteCell) {
        $cell = $putLast($avar->takes, $cb);
        $drainVar($util, $avar);
        return function () use ($cell, $deleteCell) {
            $deleteCell($cell);
        };
    };
};

$exports['_readVar'] = function ($util, $avar, $cb) use ($putLast, $drainVar, $deleteCell) {
    return function () use ($util, $avar, $cb, $putLast, $drainVar, $deleteCell) {
        $cell = $putLast($avar->reads, $cb);
        $drainVar($util, $avar);
        return function () use ($cell, $deleteCell) {
            $deleteCell($cell);
        };
    };
};

$exports['_tryPutVar'] = function ($util, $value, $avar) use ($AVar_EMPTY, $drainVar) {
    return function () use ($util, $value, $avar, $AVar_EMPTY, $drainVar) {
        if ($avar->value === $AVar_EMPTY && $avar->error === null) {
            $avar->value = $value;
            $drainVar($util, $avar);
            return true;
        } else {
            return false;
        }
    };
};

$exports['_tryTakeVar'] = function ($util, $avar) use ($AVar_EMPTY, $drainVar) {
    return function () use ($util, $avar, $AVar_EMPTY, $drainVar) {
        $value = $avar->value;
        if ($value === $AVar_EMPTY) {
            return $util->nothing;
        } else {
            $avar->value = $AVar_EMPTY;
            $drainVar($util, $avar);
            return $util->just($value);
        }
    };
};

$exports['_tryReadVar'] = function ($util, $avar) use ($AVar_EMPTY) {
    return function () use ($util, $avar, $AVar_EMPTY) {
        if ($avar->value === $AVar_EMPTY) {
            return $util->nothing;
        } else {
            return $util->just($avar->value);
        }
    };
};

$exports['_status'] = function ($util, $avar) use ($AVar_EMPTY) {
    return function () use ($util, $avar, $AVar_EMPTY) {
        if ($avar->error) {
            return $util->killed($avar->error);
        }
        if ($avar->value === $AVar_EMPTY) {
            return $util->empty;
        }
        return $util->filled($avar->value);
    };
};

return $exports;
