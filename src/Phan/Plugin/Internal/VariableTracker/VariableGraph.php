<?php declare(strict_types=1);
namespace Phan\Plugin\Internal\VariableTracker;

use ast\Node;
use function spl_object_id;

/**
 * This represents a summary of all of the definitions and uses of all variable within a scope.
 */
final class VariableGraph
{
    /**
     * @var array<string,array<int,array<int,true>>>
     *
     * Maps variable name to (definition id to (list of uses of that given definition))
     */
    public $def_uses = [];

    /**
     * @var array<string,array<int,int>>
     *
     * Maps variable id to variable line
     */
    public $def_lines = [];

    /**
     * @var array<int,true>
     *
     * The set of definition ids that are possibly placeholder loop values
     * in foreach over keys.
     */
    public $loop_def_ids = [];

    /**
     * @var array<string,int> maps variable names to whether
     *    they have ever occurred as a given self::IS_* category in the current scope
     */
    public $variable_types = [];

    const IS_REFERENCE      = 1<<0;
    const IS_GLOBAL         = 1<<1;
    const IS_STATIC         = 1<<2;

    const IS_REFERENCE_OR_GLOBAL_OR_STATIC = self::IS_REFERENCE | self::IS_GLOBAL | self::IS_STATIC;

    public function __construct()
    {
    }

    /**
     * @return void
     */
    public function recordVariableDefinition(string $name, Node $node, VariableTrackingScope $scope)
    {
        // TODO: Measure performance against SplObjectHash
        $id = \spl_object_id($node);
        if (!isset($this->def_uses[$name][$id])) {
            $this->def_uses[$name][$id] = [];
        }
        $this->def_lines[$name][$id] = $node->lineno;
        $scope->recordDefinitionById($name, $id);
    }

    /**
     * @return void
     */
    public function recordVariableUsage(string $name, Node $node, VariableTrackingScope $scope)
    {
        $defs_for_variable = $scope->getDefinition($name);
        if (!$defs_for_variable) {
            return;
        }
        $node_id = \spl_object_id($node);
        $scope->recordUsageById($name, $node_id);
        foreach ($defs_for_variable as $def_id => $_) {
            if ($def_id !== $node_id) {
                $this->def_uses[$name][$def_id][$node_id] = true;
            }
        }
    }

    /**
     * @return void
     */
    public function recordLoopSelfUsage(string $name, int $def_id, array $loop_uses_of_own_variable)
    {
        foreach ($loop_uses_of_own_variable as $node_id => $_) {
            $this->def_uses[$name][$def_id][$node_id] = true;
        }
    }

    /**
     * @return void
     */
    public function markAsReference(string $name)
    {
        $this->markBitForVariableName($name, self::IS_REFERENCE);
    }

    /**
     * @return void
     */
    public function markAsStaticVariable(string $name)
    {
        $this->markBitForVariableName($name, self::IS_STATIC);
    }

    /**
     * @return void
     */
    public function markAsGlobalVariable(string $name)
    {
        $this->markBitForVariableName($name, self::IS_GLOBAL);
    }

    /**
     * Marks something as being a loop variable `$v` in `foreach ($arr as $k => $v)`
     * (Common false positive, since there's no way to avoid setting the value)
     *
     * @return void
     */
    public function markAsLoopValueNode($node)
    {
        if ($node instanceof Node) {
            $this->loop_def_ids[spl_object_id($node)] = true;
        }
    }

    /**
     * Checks if the node for this id is defined as the value in a foreach over keys of an array.
     */
    public function isLoopValueDefinitionId(int $definition_id) : bool
    {
        return \array_key_exists($definition_id, $this->loop_def_ids);
    }

    /**
     * @return void
     */
    private function markBitForVariableName(string $name, int $bit)
    {
        $this->variable_types[$name] = (($this->variable_types[$name] ?? 0) | $bit);
    }
}
