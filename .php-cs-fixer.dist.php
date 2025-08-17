<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
;

return (new PhpCsFixer\Config())
    ->setRules(array(
        '@PSR12'                                => true,
        'align_multiline_comment'               => true,
        'array_indentation'                     => true,
        'no_extra_blank_lines'                  => true,
        'align_multiline_comment'               => true,
        'whitespace_after_comma_in_array'       => true,
        'no_trailing_comma_in_singleline_array' => true,
        'trailing_comma_in_multiline'           => true,
        'no_multiple_statements_per_line'       => true,
        'yoda_style'                            => true,
        'trim_array_spaces'                     => true,
        'binary_operator_spaces'                => array('operators' => array('=>' => 'align_single_space_minimal_by_scope', '|' => 'no_space', '===' => 'align_single_space_minimal', '==' => 'align_single_space_minimal', 'xor' => null, '=' => 'align_single_space_minimal', '&&' => 'align_single_space_minimal', '||' => 'align_single_space_minimal')),
        'array_syntax'                          => array('syntax' => 'long'),

    ))
    ->setFinder($finder)
    ->setIndent("    ")
    ->setLineEnding("\r\n")
;
