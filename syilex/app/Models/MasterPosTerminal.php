<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Traits\HasUlid;
use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterPosTerminal extends Model
{
    use HasFactory, HasUlid, HasCreatedUpdatedBy;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_pos_terminal';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'kode_terminal',
        'nama_terminal',
        'warehouse_id',
        'default_customer_id',
        'default_metode_pembayaran_id',
        'template_struk_id',
        'default_printer',
        'mail_driver',
        'mail_from_address',
        'mail_from_name',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'resend_api_key',
        'auto_open_tray',
        'auto_print_receipt',
        'auto_print_retur',
        'auto_print_kas',
        'auto_print_report',
        'auto_lock_minutes',
        'paper_width',
        'char_per_line',
        'paper_mode',
        'print_feed_before_cut',
        'izinkan_retur',
        'durasi_retur',
        'active_user_id',
        'keterangan',
        'status',
        'created_by',
        'updated_by',
        'smtp_password',
        'resend_api_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'izinkan_retur' => 'boolean',
            'auto_open_tray' => 'boolean',
            'auto_print_receipt' => 'boolean',
            'auto_print_retur' => 'boolean',
            'auto_print_kas' => 'boolean',
            'auto_print_report' => 'boolean',
            'smtp_password' => 'encrypted',
            'resend_api_key' => 'encrypted',
            'created_at' => LocalDateTime::class,
            'updated_at' => LocalDateTime::class,
        ];
    }

    /**
     * Check if terminal is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if terminal is currently in use.
     */
    public function isInUse(): bool
    {
        if ($this->active_user_id !== null) {
            return true;
        }

        // Orphan open shift (active_user cleared but shift still open)
        if (array_key_exists('active_shift', $this->getRelations())) {
            return $this->getRelation('active_shift') !== null;
        }

        return $this->activeShift()->exists();
    }

    public function isMailConfigured(): bool
    {
        return match ($this->mail_driver) {
            'smtp' => filled($this->smtp_host) && filled($this->smtp_port) && filled($this->mail_from_address),
            'resend' => filled($this->resend_api_key) && filled($this->mail_from_address),
            default => false,
        };
    }

    public function sales()
    {
        return $this->hasMany(DocSales::class, 'terminal_id');
    }

    public function cashTransactions()
    {
        return $this->hasMany(PosCashTransaction::class, 'terminal_id');
    }

    /**
     * Scope for active terminals.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for search by kode or nama.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('kode_terminal', 'like', "%{$search}%")
              ->orWhere('nama_terminal', 'like', "%{$search}%");
        });
    }

    /**
     * Get the warehouse for this terminal.
     */
    public function warehouse()
    {
        return $this->belongsTo(MasterWarehouse::class, 'warehouse_id');
    }

    /**
     * Get the default customer for this terminal.
     */
    public function defaultCustomer()
    {
        return $this->belongsTo(MasterCustomer::class, 'default_customer_id');
    }

    /**
     * Get the default payment method for this terminal.
     */
    public function defaultMetodePembayaran()
    {
        return $this->belongsTo(MasterMetodePembayaran::class, 'default_metode_pembayaran_id');
    }

    /**
     * Get the active user on this terminal.
     */
    public function activeUser()
    {
        return $this->belongsTo(User::class, 'active_user_id');
    }

    /**
     * Get the assigned users for this terminal.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'pos_terminal_users', 'terminal_id', 'user_id')
            ->withPivot('created_at');
    }

    /**
     * Get the allowed payment methods for this terminal.
     */
    public function allowedPaymentMethods()
    {
        return $this->belongsToMany(MasterMetodePembayaran::class, 'pos_terminal_payment_methods', 'terminal_id', 'metode_pembayaran_id');
    }

    /**
     * Get all shifts for this terminal.
     */
    public function shifts()
    {
        return $this->hasMany(PosTerminalShift::class, 'terminal_id');
    }

    /**
     * Get the active shift for this terminal.
     */
    public function activeShift()
    {
        return $this->hasOne(PosTerminalShift::class, 'terminal_id')->whereNull('ended_at');
    }
}
