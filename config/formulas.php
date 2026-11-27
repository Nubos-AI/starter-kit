<?php

declare(strict_types=1);

use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\ConcatFunctionHandler;
use App\Handlers\Formulas\DateDifFunctionHandler;
use App\Handlers\Formulas\IfFunctionHandler;
use App\Handlers\Formulas\RoundFunctionHandler;
use App\Handlers\Formulas\SumFunctionHandler;
use App\Handlers\Formulas\TodayFunctionHandler;
use App\Handlers\Formulas\TrimFunctionHandler;

return [
    'max_formula_length' => (int) env('FORMULAS_MAX_LENGTH', 2000),

    'max_nesting_depth' => (int) env('FORMULAS_MAX_NESTING_DEPTH', 32),

    'scale' => (int) env('FORMULAS_SCALE', 10),

    'max_evaluation_steps' => (int) env('FORMULAS_MAX_EVALUATION_STEPS', 10000),

    'backfill_batch_size' => (int) env('FORMULAS_BACKFILL_BATCH_SIZE', 200),

    'backfill_checkpoint_batches' => (int) env('FORMULAS_BACKFILL_CHECKPOINT_BATCHES', 25),

    'backfill_max_batch_attempts' => (int) env('FORMULAS_BACKFILL_MAX_BATCH_ATTEMPTS', 3),

    'function_handlers' => [
        FormulaFunction::IfThenElse->value => IfFunctionHandler::class,
        FormulaFunction::Sum->value => SumFunctionHandler::class,
        FormulaFunction::Round->value => RoundFunctionHandler::class,
        FormulaFunction::Concat->value => ConcatFunctionHandler::class,
        FormulaFunction::DateDif->value => DateDifFunctionHandler::class,
        FormulaFunction::Today->value => TodayFunctionHandler::class,
        FormulaFunction::Trim->value => TrimFunctionHandler::class,
    ],
];
