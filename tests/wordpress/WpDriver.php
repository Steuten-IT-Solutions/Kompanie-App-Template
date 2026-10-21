<?php
/**
 * Treiber des gemeinsamen API-Szenarios für WordPress (rest_do_request im selben Prozess).
 */

namespace KompanieApp\Contract;

use KompanieApp\WordPress\Plugin;
use KompanieApp\WordPress\Roles;

final class WpDriver implements ApiDriver
{
    private ?string $preview = null;

    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $req = new \WP_REST_Request($method, '/kompanie-app/v1' . $path);
        $req->set_query_params(array_map(static fn ($v) => (string) $v, $query));
        if ($body !== []) {
            $req->set_header('content-type', 'application/json');
            $req->set_body((string) wp_json_encode($body));
        }
        if ($this->preview !== null) {
            $req->set_header('X-Kompanie-Preview', $this->preview);
        }
        $res = rest_do_request($req);
        $data = json_decode((string) wp_json_encode(rest_get_server()->response_to_data($res, false)), true);
        return [$res->get_status(), $data, array_change_key_case($res->get_headers())];
    }

    public function actAs(?int $userId): void
    {
        wp_set_current_user($userId ?? 0);
    }

    public function preview(?string $value): void
    {
        $this->preview = $value;
    }

    public function createUser(string $login, string $level): int
    {
        $existing = get_user_by('login', $login);
        if ($existing) {
            wp_delete_user($existing->ID);
        }
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'display_name' => ucfirst($login), 'role' => 'subscriber']);
        if ($level === 'admin') {
            (new \WP_User($id))->set_role('administrator');
        } else {
            // Testaufbau, nicht Teil des Szenarios: ohne handelnden Benutzer.
            $acting = get_current_user_id();
            wp_set_current_user(0);
            Plugin::app()->users->setRole($id, $level);
            wp_set_current_user($acting);
        }
        clean_user_cache($id);
        return (int) $id;
    }

    public function deleteUser(int $id): void
    {
        $db = Plugin::app()->db;
        $db->execute('DELETE FROM {points} WHERE user_id = ? OR created_by = ?', [$id, $id]);
        $db->execute('DELETE FROM {penalties} WHERE user_id = ? OR created_by = ?', [$id, $id]);
        $db->execute('DELETE FROM {events} WHERE created_by = ?', [$id]);
        $db->execute("DELETE FROM {point_catalog} WHERE title LIKE 'Teilnahme Sommerfest'");
        wp_delete_user($id);
    }

    public function setPassword(int $id, string $password): void
    {
        wp_set_password($password, $id);
        clean_user_cache($id);
    }

    public function checkPassword(int $id, string $password): bool
    {
        clean_user_cache($id);
        $u = get_userdata($id);
        return $u && wp_check_password($password, $u->user_pass, $id);
    }

    public function canAssignAdmin(): bool
    {
        return false;
    }
}
