# ClassEngage API Documentation

## Core Library Functions (lib.php)

### Navigation

#### `classengage_render_tabs($cmid, $activetab = null)`

Renders consistent tab navigation across all ClassEngage pages using Moodle's standard `print_tabs()` function.

**Parameters:**
- `$cmid` (int) - Course module ID
- `$activetab` (string|null) - Active tab identifier or null for no active tab
  - Valid values: `'slides'`, `'questions'`, `'sessions'`, `'analytics'`, `null`

**Returns:** void (outputs HTML directly)

**Usage Examples:**

```php
// In slides.php - highlight the slides tab
classengage_render_tabs($cm->id, 'slides');

// In questions.php - highlight the questions tab
classengage_render_tabs($cm->id, 'questions');

// In sessions.php - highlight the sessions tab
classengage_render_tabs($cm->id, 'sessions');

// In analytics.php - highlight the analytics tab
classengage_render_tabs($cm->id, 'analytics');

// In controlpanel.php - no active tab (dedicated monitoring page)
classengage_render_tabs($cm->id, null);
```

**Generated Tabs:**

| Tab | Label | URL |
|-----|-------|-----|
| slides | Upload Slides | `/mod/classengage/slides.php?id={cmid}` |
| questions | Manage Questions | `/mod/classengage/questions.php?id={cmid}` |
| sessions | Quiz Sessions | `/mod/classengage/sessions.php?id={cmid}` |
| analytics | Analytics | `/mod/classengage/analytics.php?id={cmid}` |

**Implementation Details:**

- Uses Moodle's `tabobject` class for tab definition
- Uses Moodle's `print_tabs()` function for rendering
- Consistent with Moodle's standard tab navigation pattern
- Language strings retrieved via `get_string()`
- URLs constructed with `moodle_url` for proper parameter handling

**Added:** Version 2025110305

---

## Activity Module Functions

### Instance Management

#### `classengage_add_instance($classengage, $mform = null)`

Creates a new ClassEngage activity instance.

**Parameters:**
- `$classengage` (stdClass) - Activity data from mod_form.php
- `$mform` (mod_classengage_mod_form|null) - Form instance (optional)

**Returns:** int - ID of newly created instance

**Side Effects:**
- Creates database record in `classengage` table
- Creates gradebook item via `classengage_grade_item_update()`

---

#### `classengage_update_instance($classengage, $mform = null)`

Updates an existing ClassEngage activity instance.

**Parameters:**
- `$classengage` (stdClass) - Updated activity data
- `$mform` (mod_classengage_mod_form|null) - Form instance (optional)

**Returns:** bool - Success status

**Side Effects:**
- Updates database record
- Updates gradebook item

---

#### `classengage_delete_instance($id)`

Deletes a ClassEngage activity instance and all related data.

**Parameters:**
- `$id` (int) - Activity instance ID

**Returns:** bool - Success status

**Side Effects:**
- Deletes all slides, questions, sessions, and responses
- Deletes gradebook item
- Cascading deletion of dependent records

---

### Grading Functions

#### `classengage_grade_item_update($classengage, $grades = null)`

Creates or updates the gradebook item for a ClassEngage activity.

**Parameters:**
- `$classengage` (stdClass) - Activity instance
- `$grades` (mixed|null) - Grade data or 'reset' to reset grades

**Returns:** int - 0 if successful, error code otherwise

---

#### `classengage_grade_item_delete($classengage)`

Deletes the gradebook item for a ClassEngage activity.

**Parameters:**
- `$classengage` (stdClass) - Activity instance

**Returns:** int - 0 if successful, error code otherwise

---

#### `classengage_update_grades($classengage, $userid = 0, $nullifnone = true)`

Updates grades in the gradebook for one or all users.

**Parameters:**
- `$classengage` (stdClass) - Activity instance
- `$userid` (int) - Specific user ID, or 0 for all users
- `$nullifnone` (bool) - Return null if no grade exists

**Returns:** void

---

#### `classengage_get_user_grades($classengage, $userid = 0)`

Retrieves grades for one or all users.

**Parameters:**
- `$classengage` (stdClass) - Activity instance
- `$userid` (int) - Specific user ID, or 0 for all users

**Returns:** array - Array of grade objects with userid and rawgrade

**Grade Calculation:**
- Uses maximum score across all responses
- Score = (correct responses / total responses) × 100

---

### Feature Support

#### `classengage_supports($feature)`

Declares which Moodle features this module supports.

**Parameters:**
- `$feature` (string) - FEATURE_xx constant

**Returns:** mixed - true/false/null

**Supported Features:**
- `FEATURE_MOD_INTRO` - Activity description
- `FEATURE_BACKUP_MOODLE2` - Backup/restore
- `FEATURE_SHOW_DESCRIPTION` - Show description on course page
- `FEATURE_GRADE_HAS_GRADE` - Gradebook integration
- `FEATURE_GROUPS` - Group support

---

## See Also

- [Clicker API Documentation](../CLICKER_API_DOCUMENTATION.md)
- [Load Testing Guide](../tests/LOAD_TESTING.md)
- [Implementation Summary](../IMPLEMENTATION_SUMMARY.md)
- [README](../README.md)
