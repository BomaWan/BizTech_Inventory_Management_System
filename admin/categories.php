<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('admin');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = post_int('id');
    try {
        if ($action === 'save') {
            $name = post_str('name', 100);
            $desc = post_str('description', 255);
            if ($name === '') throw new RuntimeException('Category name is required.');
            $dup = db()->prepare('SELECT id FROM categories WHERE name = ? AND id <> ?');
            $dup->execute([$name, $id]);
            if ($dup->fetch()) throw new RuntimeException("A category named \"$name\" already exists.");
            if ($id) {
                $st = db()->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?');
                $st->execute([$name, $desc ?: null, $id]);
                flash('success', "Category \"$name\" updated.");
            } else {
                db()->prepare('INSERT INTO categories (name, description, created_at) VALUES (?,?,?)')->execute([$name, $desc ?: null, now()]);
                flash('success', "Category \"$name\" added.");
            }
        } elseif ($action === 'delete') {
            $st = db()->prepare('SELECT c.name, (SELECT COUNT(*) FROM devices WHERE category_id = c.id) n FROM categories c WHERE c.id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) throw new RuntimeException('Category not found.');
            if ((int)$row['n'] > 0) {
                throw new RuntimeException("\"{$row['name']}\" still has {$row['n']} device(s). Move or delete those devices first.");
            }
            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('success', "Category \"{$row['name']}\" deleted.");
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/categories.php');
}

$rows = db()->query("SELECT c.*, COUNT(d.id) AS device_types, COALESCE(SUM(d.quantity_total),0) AS units
                     FROM categories c LEFT JOIN devices d ON d.category_id = c.id
                     GROUP BY c.id ORDER BY c.name")->fetchAll();
$avail = [];
foreach (db()->query(device_select_sql())->fetchAll() as $d) {
    $avail[(int)$d['category_id']] = ($avail[(int)$d['category_id']] ?? 0) + (int)$d['available'];
}

$pageTitle = 'Manage Categories';
$pageSubtitle = 'Group devices into categories such as Robotics, IoT, Sensors, Boards and Motors';
$active = 'categories';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2><?= icon('tag') ?> Categories <span class="badge badge-muted"><?= count($rows) ?></span></h2>
        <div class="actions"><button class="btn btn-sky" type="button" data-modal="cat-modal" data-reset data-title="Add category" data-fill='{"id":"0"}'><?= icon('plus') ?> Add category</button></div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Description</th><th class="num">Device types</th><th class="num">Total units</th><th class="num">Available</th><th class="num">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c):
                $fill = json_encode(['id' => (int)$c['id'], 'name' => $c['name'], 'description' => $c['description']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>
                <tr>
                    <td><strong style="color:var(--navy)"><?= e($c['name']) ?></strong></td>
                    <td class="muted"><?= e($c['description'] ?: '—') ?></td>
                    <td class="num"><?= (int)$c['device_types'] ?></td>
                    <td class="num"><?= (int)$c['units'] ?></td>
                    <td class="num pill-qty"><?= (int)($avail[(int)$c['id']] ?? 0) ?></td>
                    <td class="num">
                        <div class="btn-group" style="justify-content:flex-end">
                            <a class="btn btn-sm btn-light" href="<?= e(url('admin/devices.php?cat=' . (int)$c['id'])) ?>" title="View devices"><?= icon('eye') ?></a>
                            <button class="btn btn-sm btn-light" type="button" data-modal="cat-modal" data-reset data-title="Edit category" data-fill='<?= $fill ?>' title="Edit"><?= icon('edit') ?></button>
                            <form class="inline" method="post" data-confirm="Delete category &quot;<?= e($c['name']) ?>&quot;?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-sm btn-outline-red" type="submit" title="Delete"><?= icon('trash') ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6"><div class="empty"><?= icon('tag') ?><h3>No categories yet</h3></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<dialog class="modal" id="cat-modal">
    <form method="post">
        <div class="modal-head"><h3 data-modal-title>Add category</h3><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
            <div class="field"><label for="c-name">Name</label><input type="text" id="c-name" name="name" maxlength="100" required></div>
            <div class="field mb-0"><label for="c-desc">Description <span class="hint">(optional)</span></label><input type="text" id="c-desc" name="description" maxlength="255"></div>
        </div>
        <div class="modal-foot"><button class="btn btn-light" type="button" data-close>Cancel</button><button class="btn btn-sky" type="submit"><?= icon('check') ?> Save</button></div>
    </form>
</dialog>
<?php require __DIR__ . '/../includes/footer.php'; ?>
