@php($locale = app()->getLocale())
@includeFirst(["prompts.icp-{$locale}", 'prompts.icp-en'], ['project' => $project])
