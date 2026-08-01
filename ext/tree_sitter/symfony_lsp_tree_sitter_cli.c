#include "vendor/tree-sitter/lib/tree_sitter/api.h"

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

const TSLanguage *tree_sitter_twig(void);
const TSLanguage *tree_sitter_yaml(void);

typedef struct {
    TSNode node;
    long parent;
    const char *field;
} pending_node;

static void write_json_string(const char *value)
{
    const unsigned char *cursor = (const unsigned char *) value;
    putchar('"');
    while (*cursor != '\0') {
        switch (*cursor) {
            case '"': fputs("\\\"", stdout); break;
            case '\\': fputs("\\\\", stdout); break;
            case '\b': fputs("\\b", stdout); break;
            case '\f': fputs("\\f", stdout); break;
            case '\n': fputs("\\n", stdout); break;
            case '\r': fputs("\\r", stdout); break;
            case '\t': fputs("\\t", stdout); break;
            default:
                if (*cursor < 0x20) {
                    printf("\\u%04x", *cursor);
                } else {
                    putchar(*cursor);
                }
        }
        ++cursor;
    }
    putchar('"');
}

static char *read_source(uint32_t *length)
{
    size_t capacity = 8192;
    size_t size = 0;
    char *source = malloc(capacity);
    if (source == NULL) {
        return NULL;
    }

    while (!feof(stdin)) {
        if (size == capacity) {
            if (capacity > UINT32_MAX / 2) {
                free(source);
                return NULL;
            }
            capacity *= 2;
            char *resized = realloc(source, capacity);
            if (resized == NULL) {
                free(source);
                return NULL;
            }
            source = resized;
        }
        size += fread(source + size, 1, capacity - size, stdin);
        if (ferror(stdin) || size > UINT32_MAX) {
            free(source);
            return NULL;
        }
    }

    *length = (uint32_t) size;
    return source;
}

static int write_nodes(TSNode root)
{
    uint32_t capacity = 256;
    uint32_t count = 1;
    long next_index = 0;
    int first = 1;
    pending_node *pending = malloc(sizeof(pending_node) * capacity);
    if (pending == NULL) {
        return 0;
    }
    pending[0] = (pending_node) {root, -1, NULL};

    while (count > 0) {
        pending_node current = pending[--count];
        long index = next_index++;
        if (!first) {
            putchar(',');
        }
        first = 0;
        fputs("{\"type\":", stdout);
        write_json_string(ts_node_type(current.node));
        printf(",\"startByte\":%u,\"endByte\":%u,\"parent\":%ld,\"field\":", ts_node_start_byte(current.node), ts_node_end_byte(current.node), current.parent);
        if (current.field == NULL) {
            fputs("null", stdout);
        } else {
            write_json_string(current.field);
        }
        printf(",\"error\":%s,\"missing\":%s,\"hasError\":%s}", ts_node_is_error(current.node) ? "true" : "false", ts_node_is_missing(current.node) ? "true" : "false", ts_node_has_error(current.node) ? "true" : "false");

        uint32_t child_count = ts_node_child_count(current.node);
        while (capacity - count < child_count) {
            capacity *= 2;
            pending_node *resized = realloc(pending, sizeof(pending_node) * capacity);
            if (resized == NULL) {
                free(pending);
                return 0;
            }
            pending = resized;
        }
        for (uint32_t child_index = child_count; child_index > 0; --child_index) {
            TSNode child = ts_node_child(current.node, child_index - 1);
            if (!ts_node_is_named(child) && !ts_node_is_error(child) && !ts_node_is_missing(child)) {
                continue;
            }
            pending[count++] = (pending_node) {child, index, ts_node_field_name_for_child(current.node, child_index - 1)};
        }
    }

    free(pending);
    return 1;
}

int main(int argc, char **argv)
{
    if (argc != 2) {
        fputs("Usage: symfony-lsp-tree-sitter <twig|yaml>\n", stderr);
        return 2;
    }

    const TSLanguage *language = NULL;
    if (strcmp(argv[1], "twig") == 0) {
        language = tree_sitter_twig();
    } else if (strcmp(argv[1], "yaml") == 0) {
        language = tree_sitter_yaml();
    } else {
        fputs("Unsupported Tree-sitter language.\n", stderr);
        return 2;
    }

    uint32_t source_length = 0;
    char *source = read_source(&source_length);
    if (source == NULL) {
        fputs("Unable to read source.\n", stderr);
        return 1;
    }

    TSParser *parser = ts_parser_new();
    if (parser == NULL || !ts_parser_set_language(parser, language)) {
        free(source);
        ts_parser_delete(parser);
        fputs("Unable to initialize Tree-sitter.\n", stderr);
        return 1;
    }
    TSTree *tree = ts_parser_parse_string(parser, NULL, source, source_length);
    free(source);
    ts_parser_delete(parser);
    if (tree == NULL) {
        fputs("Tree-sitter did not return a syntax tree.\n", stderr);
        return 1;
    }

    TSNode root = ts_tree_root_node(tree);
    printf("{\"hasError\":%s,\"nodes\":[", ts_node_has_error(root) ? "true" : "false");
    int success = write_nodes(root);
    fputs("]}\n", stdout);
    ts_tree_delete(tree);

    return success ? 0 : 1;
}
