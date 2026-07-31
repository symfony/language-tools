#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "php_symfony_lsp_tree_sitter.h"
#include "vendor/tree-sitter/lib/tree_sitter/api.h"

#include <stdint.h>
#include <string.h>

const TSLanguage *tree_sitter_twig(void);
const TSLanguage *tree_sitter_yaml(void);

typedef struct {
    TSNode node;
    zend_long parent;
    const char *field;
} pending_node;

static void append_nodes(zval *nodes, TSNode root)
{
    uint32_t capacity = 256;
    uint32_t count = 1;
    pending_node *pending = emalloc(sizeof(pending_node) * capacity);
    pending[0] = (pending_node) {root, -1, NULL};

    while (count > 0) {
        pending_node current = pending[--count];
        zval item;
        array_init(&item);
        add_assoc_string(&item, "type", (char *) ts_node_type(current.node));
        add_assoc_long(&item, "startByte", ts_node_start_byte(current.node));
        add_assoc_long(&item, "endByte", ts_node_end_byte(current.node));
        add_assoc_long(&item, "parent", current.parent);
        if (current.field == NULL) {
            add_assoc_null(&item, "field");
        } else {
            add_assoc_string(&item, "field", (char *) current.field);
        }
        add_assoc_bool(&item, "error", ts_node_is_error(current.node));
        add_assoc_bool(&item, "missing", ts_node_is_missing(current.node));
        add_assoc_bool(&item, "hasError", ts_node_has_error(current.node));

        zend_long index = zend_hash_num_elements(Z_ARRVAL_P(nodes));
        add_next_index_zval(nodes, &item);

        uint32_t child_count = ts_node_child_count(current.node);
        while (capacity - count < child_count) {
            capacity *= 2;
            pending = erealloc(pending, sizeof(pending_node) * capacity);
        }
        for (uint32_t child_index = child_count; child_index > 0; child_index--) {
            TSNode child = ts_node_child(current.node, child_index - 1);
            if (!ts_node_is_named(child) && !ts_node_is_error(child) && !ts_node_is_missing(child)) {
                continue;
            }

            pending[count++] = (pending_node) {child, index, ts_node_field_name_for_child(current.node, child_index - 1)};
        }
    }

    efree(pending);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_symfony_lsp_tree_sitter_parse, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, language, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, source, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(symfony_lsp_tree_sitter_parse)
{
    char *language_name;
    size_t language_name_length;
    char *source;
    size_t source_length;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(language_name, language_name_length)
        Z_PARAM_STRING(source, source_length)
    ZEND_PARSE_PARAMETERS_END();

    const TSLanguage *language = NULL;
    if (language_name_length == sizeof("twig") - 1 && memcmp(language_name, "twig", sizeof("twig") - 1) == 0) {
        language = tree_sitter_twig();
    } else if (language_name_length == sizeof("yaml") - 1 && memcmp(language_name, "yaml", sizeof("yaml") - 1) == 0) {
        language = tree_sitter_yaml();
    } else {
        zend_value_error("unsupported Tree-sitter language \"%s\"", language_name);
        RETURN_THROWS();
    }

    if (source_length > UINT32_MAX) {
        zend_value_error("Tree-sitter source exceeds the maximum supported size");
        RETURN_THROWS();
    }

    TSParser *parser = ts_parser_new();
    if (parser == NULL) {
        zend_throw_error(NULL, "unable to allocate the Tree-sitter parser");
        RETURN_THROWS();
    }

    if (!ts_parser_set_language(parser, language)) {
        ts_parser_delete(parser);
        zend_throw_error(NULL, "Tree-sitter grammar is incompatible with the parser runtime");
        RETURN_THROWS();
    }

    TSTree *tree = ts_parser_parse_string(parser, NULL, source, (uint32_t) source_length);
    ts_parser_delete(parser);
    if (tree == NULL) {
        zend_throw_error(NULL, "Tree-sitter did not return a syntax tree");
        RETURN_THROWS();
    }

    TSNode root = ts_tree_root_node(tree);
    array_init(return_value);
    add_assoc_bool(return_value, "hasError", ts_node_has_error(root));

    zval nodes;
    array_init(&nodes);
    append_nodes(&nodes, root);
    add_assoc_zval(return_value, "nodes", &nodes);

    ts_tree_delete(tree);
}

static const zend_function_entry symfony_lsp_tree_sitter_functions[] = {
    PHP_FE(symfony_lsp_tree_sitter_parse, arginfo_symfony_lsp_tree_sitter_parse)
    PHP_FE_END
};

PHP_MINFO_FUNCTION(symfony_lsp_tree_sitter)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "Symfony LSP Tree-sitter support", "enabled");
    php_info_print_table_row(2, "Version", PHP_SYMFONY_LSP_TREE_SITTER_VERSION);
    php_info_print_table_end();
}

zend_module_entry symfony_lsp_tree_sitter_module_entry = {
    STANDARD_MODULE_HEADER,
    "symfony_lsp_tree_sitter",
    symfony_lsp_tree_sitter_functions,
    NULL,
    NULL,
    NULL,
    NULL,
    PHP_MINFO(symfony_lsp_tree_sitter),
    PHP_SYMFONY_LSP_TREE_SITTER_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_SYMFONY_LSP_TREE_SITTER
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(symfony_lsp_tree_sitter)
#endif
