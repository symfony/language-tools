<?php

return static function (): never {
    throw new RuntimeException('Trace failure.');
};
