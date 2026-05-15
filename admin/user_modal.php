<div id="userModal" class="modal">
    <div class="modal-content">

        <div class="modal-header">
            <h3>Create account</h3>
            <button type="button" id="closeUserModal" class="modal-close">×</button>
        </div>

        <form method="post" action="<?= h(app_url('admin/users_create.php')) ?>">

            <div class="form-grid">
                <div>
                    <label>Display Name</label>
                    <input type="text" name="display_name" required>
                </div>

                <div>
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>

                <div>
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>

                <div>
                    <label>Password</label>
                    <input type="password" name="password" value="Password123!" required>
                </div>

                <div>
                    <label>Role</label>
                    <select name="role_id" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= h($role['roles_id']) ?>">
                                <?= h($role['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn" type="submit">Create User</button>
            </div>

        </form>
    </div>
</div>