<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the contact_message table: messages from the public contact form
 */
class ContactMessageModel extends Model
{
    use TransactionTrait;

    public const STATUS_NEW  = 'N';
    public const STATUS_READ = 'R';
    public const STATUS_DONE = 'D';
    public const STATUS_SPAM = 'S';

    protected $table          = 'contact_message';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields  = true;
    protected $allowedFields  = [
        'name',
        'email_address',
        'phone_number',
        'subject',
        'message',
        'locale',
        'status',
        'spam_reason',
        'ip_address',
        'user_agent',
        'handled_by',
        'handled_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Store a message from the contact form, logged to log_activity in the same transaction
     * @param array $row Already validated and normalised by App\Controllers\Contact
     * @return int The new contact_message.id
     */
    public function receive(array $row): int
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($row, $log) {
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted(null, 'Received contact message from ' . $row['email_address'], $this->table, $id, $row);

            return $id;
        });
    }
}
