/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Trimmed C declarations for libgit2 1.9, hand-maintained for use with
 * PHP's FFI::cdef(). Struct layouts are transcribed from the real libgit2
 * 1.9 headers (git2/*.h) with GIT_EXPERIMENTAL_SHA256 unset (the default,
 * plain SHA1 git_oid). libgit2 breaks its ABI on every minor release (struct
 * layouts change while the *_OPTIONS_VERSION constants stay at 1), so
 * LibGit2Driver refuses to load any other minor version. Function-pointer fields that this driver never
 * populates (left zeroed) are declared as plain `void *` to avoid pulling
 * in every callback typedef: this is safe for layout purposes since a
 * function pointer and a data pointer have the same size/representation
 * on every platform libgit2 supports.
 */

typedef struct git_oid { unsigned char id[20]; } git_oid;

typedef struct { char *ptr; size_t reserved; size_t size; } git_buf;

typedef struct git_strarray { char **strings; size_t count; } git_strarray;

typedef struct git_oidarray { git_oid *ids; size_t count; } git_oidarray;

typedef struct git_time { int64_t time; int offset; char sign; } git_time;

typedef struct git_signature {
    char *name;
    char *email;
    git_time when;
} git_signature;

typedef struct git_error { char *message; int klass; } git_error;

typedef enum {
    GIT_OBJECT_ANY = -2, GIT_OBJECT_INVALID = -1,
    GIT_OBJECT_COMMIT = 1, GIT_OBJECT_TREE = 2, GIT_OBJECT_BLOB = 3, GIT_OBJECT_TAG = 4,
    GIT_OBJECT_OFS_DELTA = 6, GIT_OBJECT_REF_DELTA = 7
} git_object_t;

typedef enum {
    GIT_REFERENCE_INVALID = 0, GIT_REFERENCE_DIRECT = 1, GIT_REFERENCE_SYMBOLIC = 2, GIT_REFERENCE_ALL = 3
} git_reference_t;

typedef enum {
    GIT_BRANCH_LOCAL = 1, GIT_BRANCH_REMOTE = 2, GIT_BRANCH_ALL = 3
} git_branch_t;

typedef enum {
    GIT_FILEMODE_UNREADABLE = 0000000, GIT_FILEMODE_TREE = 0040000,
    GIT_FILEMODE_BLOB = 0100644, GIT_FILEMODE_BLOB_EXECUTABLE = 0100755,
    GIT_FILEMODE_LINK = 0120000, GIT_FILEMODE_COMMIT = 0160000
} git_filemode_t;

typedef struct git_repository git_repository;
typedef struct git_object git_object;
typedef struct git_commit git_commit;
typedef struct git_tree git_tree;
typedef struct git_tree_entry git_tree_entry;
typedef struct git_blob git_blob;
typedef struct git_tag git_tag;
typedef struct git_reference git_reference;
typedef struct git_reference_iterator git_reference_iterator;
typedef struct git_reflog git_reflog;
typedef struct git_reflog_entry git_reflog_entry;
typedef struct git_branch_iterator git_branch_iterator;
typedef struct git_revwalk git_revwalk;
typedef struct git_index git_index;
typedef struct git_diff git_diff;
typedef struct git_blame git_blame;
typedef struct git_status_list git_status_list;
typedef struct git_patch git_patch;
typedef struct git_remote git_remote;
typedef struct git_refspec git_refspec;
typedef struct git_config git_config;

/* -- global -- */

int git_libgit2_init(void);
int git_libgit2_version(int *major, int *minor, int *rev);
int git_libgit2_features(void);
int git_libgit2_opts(int option, ...);

/* -- buffers / signatures / arrays -- */

void git_buf_dispose(git_buf *buffer);
int git_signature_default_from_env(git_signature **author_out, git_signature **committer_out, git_repository *repo);
void git_signature_free(git_signature *sig);
void git_oidarray_dispose(git_oidarray *array);
void git_strarray_dispose(git_strarray *array);
int git_message_prettify(git_buf *out, const char *message, int strip_comments, char comment_char);

/* -- errors -- */

const git_error *git_error_last(void);

/* -- oid -- */

int git_oid_fromstrn(git_oid *out, const char *str, size_t length);
char *git_oid_tostr(char *out, size_t n, const git_oid *id);
int git_oid_is_zero(const git_oid *id);

/* -- repository -- */

int git_repository_open(git_repository **out, const char *path);
int git_repository_set_workdir(git_repository *repo, const char *workdir, int update_gitlink);
void git_repository_free(git_repository *repo);
int git_repository_init(git_repository **out, const char *path, unsigned is_bare);
int git_repository_head(git_reference **out, git_repository *repo);
int git_repository_set_head(git_repository *repo, const char *refname);
int git_repository_set_head_detached(git_repository *repo, const git_oid *committish);
int git_repository_config(git_config **out, git_repository *repo);
int git_repository_index(git_index **out, git_repository *repo);

/* -- config -- */

int git_config_set_string(git_config *cfg, const char *name, const char *value);
void git_config_free(git_config *cfg);

/* -- object / commit / tree / blob / tag -- */

int git_object_lookup_prefix(git_object **object_out, git_repository *repo, const git_oid *id, size_t len, git_object_t type);
const git_oid *git_object_id(const git_object *obj);
git_object_t git_object_type(const git_object *obj);
int git_object_short_id(git_buf *out, const git_object *obj);
int git_object_peel(git_object **peeled, const git_object *object, git_object_t target_type);
void git_object_free(git_object *object);

int git_commit_lookup_prefix(git_commit **commit, git_repository *repo, const git_oid *id, size_t len);
void git_commit_free(git_commit *commit);
const git_oid *git_commit_id(const git_commit *commit);
const char *git_commit_message(const git_commit *commit);
const char *git_commit_message_encoding(const git_commit *commit);
const git_signature *git_commit_committer(const git_commit *commit);
const git_signature *git_commit_author(const git_commit *commit);
int git_commit_tree(git_tree **tree_out, const git_commit *commit);
const git_oid *git_commit_tree_id(const git_commit *commit);
unsigned int git_commit_parentcount(const git_commit *commit);
int git_commit_parent(git_commit **out, const git_commit *commit, unsigned int n);
const git_oid *git_commit_parent_id(const git_commit *commit, unsigned int n);
int git_commit_create(
    git_oid *id, git_repository *repo, const char *update_ref,
    const git_signature *author, const git_signature *committer,
    const char *message_encoding, const char *message,
    const git_tree *tree, size_t parent_count, const git_commit *parents[]);

int git_tree_lookup_prefix(git_tree **out, git_repository *repo, const git_oid *id, size_t len);
void git_tree_free(git_tree *tree);
const git_oid *git_tree_id(const git_tree *tree);
int git_tree_entry_bypath(git_tree_entry **out, const git_tree *root, const char *path);
void git_tree_entry_free(git_tree_entry *entry);
size_t git_tree_entrycount(const git_tree *tree);
const git_tree_entry *git_tree_entry_byindex(const git_tree *tree, size_t idx);
const char *git_tree_entry_name(const git_tree_entry *entry);
const git_oid *git_tree_entry_id(const git_tree_entry *entry);
git_object_t git_tree_entry_type(const git_tree_entry *entry);
git_filemode_t git_tree_entry_filemode(const git_tree_entry *entry);

int git_blob_lookup_prefix(git_blob **blob, git_repository *repo, const git_oid *id, size_t len);
void git_blob_free(git_blob *blob);
const void *git_blob_rawcontent(const git_blob *blob);
uint64_t git_blob_rawsize(const git_blob *blob);

int git_tag_lookup(git_tag **out, git_repository *repo, const git_oid *id);
void git_tag_free(git_tag *tag);
const git_oid *git_tag_target_id(const git_tag *tag);
git_object_t git_tag_target_type(const git_tag *tag);
const char *git_tag_name(const git_tag *tag);
const git_signature *git_tag_tagger(const git_tag *tag);
const char *git_tag_message(const git_tag *tag);

/* -- revparse / revwalk -- */

int git_revparse_single(git_object **out, git_repository *repo, const char *spec);

int git_revwalk_new(git_revwalk **out, git_repository *repo);
int git_revwalk_push(git_revwalk *walk, const git_oid *id);
int git_revwalk_hide(git_revwalk *walk, const git_oid *commit_id);
int git_revwalk_next(git_oid *out, git_revwalk *walk);
int git_revwalk_sorting(git_revwalk *walk, unsigned int sort_mode);
void git_revwalk_free(git_revwalk *walk);

/* -- refs / branch -- */

int git_reference_lookup(git_reference **out, git_repository *repo, const char *name);
int git_reference_dwim(git_reference **out, git_repository *repo, const char *shorthand);
int git_reference_resolve(git_reference **out, const git_reference *ref);
const git_oid *git_reference_target(const git_reference *ref);
const char *git_reference_symbolic_target(const git_reference *ref);
git_reference_t git_reference_type(const git_reference *ref);
const char *git_reference_name(const git_reference *ref);
int git_reference_create(git_reference **out, git_repository *repo, const char *name, const git_oid *id, int force, const char *log_message);
int git_reference_delete(git_reference *ref);
void git_reference_free(git_reference *ref);
int git_reference_iterator_glob_new(git_reference_iterator **out, git_repository *repo, const char *glob);
int git_reference_next(git_reference **out, git_reference_iterator *iter);
void git_reference_iterator_free(git_reference_iterator *iter);
int git_reference_peel(git_object **out, const git_reference *ref, git_object_t type);

int git_reflog_read(git_reflog **out, git_repository *repo, const char *name);
size_t git_reflog_entrycount(git_reflog *reflog);
const git_reflog_entry *git_reflog_entry_byindex(const git_reflog *reflog, size_t idx);
const char *git_reflog_entry_message(const git_reflog_entry *entry);
void git_reflog_free(git_reflog *reflog);

int git_branch_create(git_reference **out, git_repository *repo, const char *branch_name, const git_commit *target, int force);
int git_branch_delete(git_reference *branch);
int git_branch_name_is_valid(int *valid, const char *name);
int git_branch_iterator_new(git_branch_iterator **out, git_repository *repo, git_branch_t list_flags);
int git_branch_next(git_reference **out, git_branch_t *out_type, git_branch_iterator *iter);
void git_branch_iterator_free(git_branch_iterator *iter);

/* -- graph -- */

int git_graph_descendant_of(git_repository *repo, const git_oid *commit, const git_oid *ancestor);

/* -- merge_base -- */

int git_merge_bases(git_oidarray *out, git_repository *repo, const git_oid *one, const git_oid *two);

/* -- diff -- */

typedef struct {
    git_oid id;
    const char *path;
    uint64_t size;
    uint32_t flags;
    uint16_t mode;
    uint16_t id_abbrev;
} git_diff_file;

typedef struct {
    int status;
    uint32_t flags;
    uint16_t similarity;
    uint16_t nfiles;
    git_diff_file old_file;
    git_diff_file new_file;
} git_diff_delta;

typedef struct {
    unsigned int version;
    uint32_t flags;
    int ignore_submodules;
    git_strarray pathspec;
    void *notify_cb;
    void *progress_cb;
    void *payload;
    uint32_t context_lines;
    uint32_t interhunk_lines;
    int oid_type;
    uint16_t id_abbrev;
    int64_t max_size;
    const char *old_prefix;
    const char *new_prefix;
} git_diff_options;

typedef struct {
    int old_start;
    int old_lines;
    int new_start;
    int new_lines;
    size_t header_len;
    char header[128];
} git_diff_hunk;

typedef struct {
    char origin;
    int old_lineno;
    int new_lineno;
    int num_lines;
    size_t content_len;
    int64_t content_offset;
    /* Declared non-const (unlike the real libgit2 header) so PHP FFI does not
     * auto-convert it to a NUL-terminated PHP string: this span is documented
     * as *not* NUL-terminated, so it must be read with an explicit length via
     * FFI::string($line->content, $line->content_len). */
    char *content;
} git_diff_line;

typedef struct {
    unsigned int version;
    uint32_t flags;
    uint16_t rename_threshold;
    uint16_t rename_from_rewrite_threshold;
    uint16_t copy_threshold;
    uint16_t break_rewrite_threshold;
    size_t rename_limit;
    void *metric;
} git_diff_find_options;

int git_diff_options_init(git_diff_options *opts, unsigned int version);
void git_diff_free(git_diff *diff);
int git_diff_tree_to_tree(git_diff **diff, git_repository *repo, git_tree *old_tree, git_tree *new_tree, const git_diff_options *opts);
int git_diff_tree_to_index(git_diff **diff, git_repository *repo, git_tree *old_tree, git_index *index, const git_diff_options *opts);
int git_diff_tree_to_workdir(git_diff **diff, git_repository *repo, git_tree *old_tree, const git_diff_options *opts);
int git_diff_tree_to_workdir_with_index(git_diff **diff, git_repository *repo, git_tree *old_tree, const git_diff_options *opts);
int git_diff_index_to_workdir(git_diff **diff, git_repository *repo, git_index *index, const git_diff_options *opts);
int git_diff_find_options_init(git_diff_find_options *opts, unsigned int version);
int git_diff_find_similar(git_diff *diff, const git_diff_find_options *options);
size_t git_diff_num_deltas(const git_diff *diff);
const git_diff_delta *git_diff_get_delta(const git_diff *diff, size_t idx);

/* -- patch -- */

int git_patch_from_diff(git_patch **out, git_diff *diff, size_t idx);
const git_diff_delta *git_patch_get_delta(const git_patch *patch);
size_t git_patch_num_hunks(const git_patch *patch);
int git_patch_get_hunk(const git_diff_hunk **out, size_t *lines_in_hunk, git_patch *patch, size_t hunk_idx);
int git_patch_get_line_in_hunk(const git_diff_line **out, git_patch *patch, size_t hunk_idx, size_t line_of_hunk);
void git_patch_free(git_patch *patch);

/* -- blame -- */

typedef struct git_blame_options {
    unsigned int version;
    unsigned int flags;
    uint16_t min_match_characters;
    git_oid newest_commit;
    git_oid oldest_commit;
    size_t min_line;
    size_t max_line;
} git_blame_options;

typedef struct git_blame_hunk {
    size_t lines_in_hunk;
    git_oid final_commit_id;
    size_t final_start_line_number;
    git_signature *final_signature;
    git_signature *final_committer;
    git_oid orig_commit_id;
    const char *orig_path;
    size_t orig_start_line_number;
    git_signature *orig_signature;
    git_signature *orig_committer;
    const char *summary;
    char boundary;
} git_blame_hunk;

/* ->ptr declared non-const so PHP FFI does not auto-convert it to a
 * NUL-terminated string; read with FFI::string($line->ptr, $line->len). */
typedef struct git_blame_line { char *ptr; size_t len; } git_blame_line;

int git_blame_options_init(git_blame_options *opts, unsigned int version);
int git_blame_file(git_blame **out, git_repository *repo, const char *path, git_blame_options *options);
size_t git_blame_linecount(git_blame *blame);
const git_blame_hunk *git_blame_hunk_byline(git_blame *blame, size_t lineno);
const git_blame_line *git_blame_line_byindex(git_blame *blame, size_t idx);
void git_blame_free(git_blame *blame);

/* -- status -- */

typedef struct {
    unsigned int version;
    int show;
    unsigned int flags;
    git_strarray pathspec;
    git_tree *baseline;
    uint16_t rename_threshold;
} git_status_options;

typedef struct {
    int status;
    git_diff_delta *head_to_index;
    git_diff_delta *index_to_workdir;
} git_status_entry;

int git_status_options_init(git_status_options *opts, unsigned int version);
int git_status_list_new(git_status_list **out, git_repository *repo, const git_status_options *opts);
size_t git_status_list_entrycount(git_status_list *statuslist);
const git_status_entry *git_status_byindex(git_status_list *statuslist, size_t idx);
void git_status_list_free(git_status_list *statuslist);

/* -- index -- */

typedef struct {
    int32_t seconds;
    uint32_t nanoseconds;
} git_index_time;

typedef struct git_index_entry {
    git_index_time ctime;
    git_index_time mtime;
    uint32_t dev;
    uint32_t ino;
    uint32_t mode;
    uint32_t uid;
    uint32_t gid;
    uint32_t file_size;
    git_oid id;
    uint16_t flags;
    uint16_t flags_extended;
    const char *path;
} git_index_entry;

int git_index_read(git_index *index, int force);
unsigned int git_index_version(git_index *index);
size_t git_index_entrycount(const git_index *index);
const git_index_entry *git_index_get_byindex(git_index *index, size_t n);
void git_index_free(git_index *index);
int git_index_write_tree_to(git_oid *out, git_index *index, git_repository *repo);
int git_index_has_conflicts(const git_index *index);

/* -- checkout -- */

typedef struct git_checkout_options {
    unsigned int version;
    unsigned int checkout_strategy;
    int disable_filters;
    unsigned int dir_mode;
    unsigned int file_mode;
    int file_open_flags;
    unsigned int notify_flags;
    void *notify_cb;
    void *notify_payload;
    void *progress_cb;
    void *progress_payload;
    git_strarray paths;
    git_tree *baseline;
    git_index *baseline_index;
    const char *target_directory;
    const char *ancestor_label;
    const char *our_label;
    const char *their_label;
    void *perfdata_cb;
    void *perfdata_payload;
} git_checkout_options;

int git_checkout_options_init(git_checkout_options *opts, unsigned int version);
int git_checkout_tree(git_repository *repo, const git_object *treeish, const git_checkout_options *opts);

/* -- merge -- */

typedef struct {
    unsigned int version;
    uint32_t flags;
    unsigned int rename_threshold;
    unsigned int target_limit;
    void *metric;
    unsigned int recursion_limit;
    const char *default_driver;
    int file_favor;
    uint32_t file_flags;
} git_merge_options;

int git_merge_options_init(git_merge_options *opts, unsigned int version);
int git_merge_commits(git_index **out, git_repository *repo, const git_commit *our_commit, const git_commit *their_commit, const git_merge_options *opts);

/* -- clone / remote (network operations) -- */

typedef struct git_proxy_options {
    unsigned int version;
    int type;
    const char *url;
    void *credentials;
    void *certificate_check;
    void *payload;
} git_proxy_options;

typedef struct {
    unsigned int version;
    void *sideband_progress;
    void *completion;
    void *credentials;
    void *certificate_check;
    void *transfer_progress;
    void *update_tips;
    void *pack_progress;
    void *push_transfer_progress;
    void *push_update_reference;
    void *push_negotiation;
    void *transport;
    void *remote_ready;
    void *payload;
    void *resolve_url;
    void *update_refs;
} git_remote_callbacks;

typedef struct {
    int version;
    git_remote_callbacks callbacks;
    int prune;
    unsigned int update_fetchhead;
    int download_tags;
    git_proxy_options proxy_opts;
    int depth;
    int follow_redirects;
    git_strarray custom_headers;
} git_fetch_options;

typedef struct {
    unsigned int version;
    git_checkout_options checkout_opts;
    git_fetch_options fetch_opts;
    int bare;
    int local;
    const char *checkout_branch;
    void *repository_cb;
    void *repository_cb_payload;
    void *remote_cb;
    void *remote_cb_payload;
} git_clone_options;

int git_clone_options_init(git_clone_options *options, unsigned int version);
int git_clone(git_repository **out, const char *url, const char *local_path, const git_clone_options *options);

typedef struct git_remote_head {
    int local;
    git_oid oid;
    git_oid loid;
    char *name;
    char *symref_target;
} git_remote_head;

int git_fetch_options_init(git_fetch_options *opts, unsigned int version);

int git_remote_create_anonymous(git_remote **out, git_repository *repo, const char *url);
int git_remote_create_detached(git_remote **out, const char *url);
int git_remote_default_branch(git_buf *out, git_remote *remote);
int git_remote_fetch(git_remote *remote, const git_strarray *refspecs, const git_fetch_options *opts, const char *reflog_message);
int git_remote_connect(git_remote *remote, int direction, const git_remote_callbacks *callbacks, const git_proxy_options *proxy_opts, const git_strarray *custom_headers);
int git_remote_ls(const git_remote_head ***out, size_t *size, git_remote *remote);
int git_remote_disconnect(git_remote *remote);
void git_remote_free(git_remote *remote);
int git_remote_list(git_strarray *out, git_repository *repo);
int git_remote_lookup(git_remote **out, git_repository *repo, const char *name);
size_t git_remote_refspec_count(const git_remote *remote);
const git_refspec *git_remote_get_refspec(const git_remote *remote, size_t n);

/* -- refspec -- */

int git_refspec_direction(const git_refspec *spec);
int git_refspec_dst_matches(const git_refspec *refspec, const char *refname);
int git_refspec_rtransform(git_buf *out, const git_refspec *spec, const char *name);
