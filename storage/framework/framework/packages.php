<?php return array (
  'providers' => 
  array (
    0 => 'Waaseyaa\\AdminSurface\\AdminSurfaceServiceProvider',
    1 => 'Waaseyaa\\AI\\Pipeline\\AIPipelineServiceProvider',
    2 => 'Waaseyaa\\Path\\PathServiceProvider',
    3 => 'Waaseyaa\\Queue\\QueueServiceProvider',
    4 => 'Waaseyaa\\Relationship\\RelationshipServiceProvider',
    5 => 'Waaseyaa\\SSR\\ThemeServiceProvider',
    6 => 'Waaseyaa\\SSR\\SsrServiceProvider',
    7 => 'Waaseyaa\\Taxonomy\\TaxonomyServiceProvider',
    8 => 'Waaseyaa\\User\\UserServiceProvider',
    9 => 'Waaseyaa\\Workflows\\WorkflowServiceProvider',
    10 => 'Claudriel\\Provider\\AccountServiceProvider',
    11 => 'Claudriel\\Provider\\IngestionServiceProvider',
    12 => 'Claudriel\\Provider\\CommitmentServiceProvider',
    13 => 'Claudriel\\Provider\\CommitmentToolServiceProvider',
    14 => 'Claudriel\\Provider\\ChatServiceProvider',
    15 => 'Claudriel\\Provider\\MemoryServiceProvider',
    16 => 'Claudriel\\Provider\\DayBriefServiceProvider',
    17 => 'Claudriel\\Provider\\ProjectServiceProvider',
    18 => 'Claudriel\\Provider\\RepoServiceProvider',
    19 => 'Claudriel\\Provider\\WorkspaceServiceProvider',
    20 => 'Claudriel\\Provider\\OperationsServiceProvider',
    21 => 'Claudriel\\Provider\\TemporalServiceProvider',
    22 => 'Claudriel\\Provider\\PersonToolServiceProvider',
    23 => 'Claudriel\\Provider\\WorkspaceToolServiceProvider',
    24 => 'Claudriel\\Provider\\TelescopeServiceProvider',
    25 => 'Claudriel\\Provider\\CacheServiceProvider',
    26 => 'Claudriel\\Provider\\QueueServiceProvider',
    27 => 'Claudriel\\Provider\\StateServiceProvider',
    28 => 'Claudriel\\Provider\\MailServiceProvider',
    29 => 'Waaseyaa\\Relationship\\RelationshipServiceProvider',
    30 => 'Waaseyaa\\Taxonomy\\TaxonomyServiceProvider',
    31 => 'Claudriel\\Provider\\AiVectorServiceProvider',
    32 => 'Claudriel\\Provider\\PipelineServiceProvider',
    33 => 'Claudriel\\Provider\\ProspectToolServiceProvider',
    34 => 'Claudriel\\Provider\\McpServiceProvider',
    35 => 'Claudriel\\Provider\\ClaudrielServiceProvider',
    36 => 'Claudriel\\Provider\\I18nServiceProvider',
    37 => 'Claudriel\\Provider\\SearchToolServiceProvider',
    38 => 'Claudriel\\Provider\\JudgmentRuleServiceProvider',
  ),
  'commands' => 
  array (
  ),
  'routes' => 
  array (
  ),
  'migrations' => 
  array (
  ),
  'field_types' => 
  array (
  ),
  'formatters' => 
  array (
    'boolean' => 'Waaseyaa\\SSR\\Formatter\\BooleanFormatter',
    'datetime' => 'Waaseyaa\\SSR\\Formatter\\DateFormatter',
    'entity_reference' => 'Waaseyaa\\SSR\\Formatter\\EntityReferenceFormatter',
    'text_long' => 'Waaseyaa\\SSR\\Formatter\\HtmlFormatter',
    'image' => 'Waaseyaa\\SSR\\Formatter\\ImageFormatter',
    'string' => 'Waaseyaa\\SSR\\Formatter\\PlainTextFormatter',
  ),
  'listeners' => 
  array (
  ),
  'middleware' => 
  array (
    'http' => 
    array (
      0 => 
      array (
        'class' => 'Waaseyaa\\Foundation\\Middleware\\SecurityHeadersMiddleware',
        'priority' => 100,
      ),
      1 => 
      array (
        'class' => 'Waaseyaa\\Telescope\\Middleware\\TelescopeRequestMiddleware',
        'priority' => 100,
      ),
      2 => 
      array (
        'class' => 'Waaseyaa\\Foundation\\Middleware\\CompressionMiddleware',
        'priority' => 90,
      ),
      3 => 
      array (
        'class' => 'Waaseyaa\\Foundation\\Middleware\\RateLimitMiddleware',
        'priority' => 80,
      ),
      4 => 
      array (
        'class' => 'Waaseyaa\\Foundation\\Middleware\\BodySizeLimitMiddleware',
        'priority' => 70,
      ),
      5 => 
      array (
        'class' => 'Waaseyaa\\Foundation\\Middleware\\RequestLoggingMiddleware',
        'priority' => 60,
      ),
      6 => 
      array (
        'class' => 'Waaseyaa\\Foundation\\Middleware\\ETagMiddleware',
        'priority' => 50,
      ),
      7 => 
      array (
        'class' => 'Waaseyaa\\User\\Middleware\\BearerAuthMiddleware',
        'priority' => 40,
      ),
      8 => 
      array (
        'class' => 'Claudriel\\Routing\\AccountSessionMiddleware',
        'priority' => 31,
      ),
      9 => 
      array (
        'class' => 'Waaseyaa\\User\\Middleware\\SessionMiddleware',
        'priority' => 30,
      ),
      10 => 
      array (
        'class' => 'Waaseyaa\\User\\Middleware\\CsrfMiddleware',
        'priority' => 20,
      ),
      11 => 
      array (
        'class' => 'Waaseyaa\\Access\\Middleware\\AuthorizationMiddleware',
        'priority' => 10,
      ),
    ),
  ),
  'permissions' => 
  array (
  ),
  'policies' => 
  array (
    'Claudriel\\Access\\ChatSessionAccessPolicy' => 
    array (
      0 => 'chat_session',
    ),
    'Claudriel\\Access\\CommitmentAccessPolicy' => 
    array (
      0 => 'commitment',
    ),
    'Claudriel\\Access\\JunctionAccessPolicy' => 
    array (
      0 => 'project_repo',
      1 => 'workspace_project',
      2 => 'workspace_repo',
    ),
    'Claudriel\\Access\\McEventAccessPolicy' => 
    array (
      0 => 'mc_event',
    ),
    'Claudriel\\Access\\PersonAccessPolicy' => 
    array (
      0 => 'person',
    ),
    'Claudriel\\Access\\PipelineLeadEntityAccessPolicy' => 
    array (
      0 => 'prospect',
      1 => 'pipeline_config',
      2 => 'filtered_prospect',
      3 => 'prospect_attachment',
      4 => 'prospect_audit',
    ),
    'Claudriel\\Access\\ProjectAccessPolicy' => 
    array (
      0 => 'project',
    ),
    'Claudriel\\Access\\RepoAccessPolicy' => 
    array (
      0 => 'repo',
    ),
    'Claudriel\\Access\\ScheduleEntryAccessPolicy' => 
    array (
      0 => 'schedule_entry',
    ),
    'Claudriel\\Access\\TriageEntryAccessPolicy' => 
    array (
      0 => 'triage_entry',
    ),
    'Claudriel\\Access\\WorkspaceAccessPolicy' => 
    array (
      0 => 'workspace',
    ),
    'Waaseyaa\\Access\\ConfigEntityAccessPolicy' => 
    array (
      0 => 'node_type',
      1 => 'taxonomy_vocabulary',
      2 => 'media_type',
      3 => 'workflow',
      4 => 'pipeline',
      5 => 'path_alias',
      6 => 'menu',
      7 => 'menu_link',
    ),
    'Waaseyaa\\Path\\PathAliasAccessPolicy' => 
    array (
      0 => 'path_alias',
    ),
    'Waaseyaa\\Relationship\\RelationshipAccessPolicy' => 
    array (
      0 => 'relationship',
    ),
    'Waaseyaa\\Taxonomy\\TermAccessPolicy' => 
    array (
      0 => 'taxonomy_term',
    ),
    'Waaseyaa\\User\\UserAccessPolicy' => 
    array (
      0 => 'user',
    ),
  ),
);
