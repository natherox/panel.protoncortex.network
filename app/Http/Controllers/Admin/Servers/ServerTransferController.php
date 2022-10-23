<?php

namespace Pterodactyl\Http\Controllers\Admin\Servers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Allocation;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\ServerTransfer;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Servers\TransferService;
use Pterodactyl\Repositories\Eloquent\NodeRepository;
use Pterodactyl\Repositories\Wings\DaemonConfigurationRepository;

class ServerTransferController extends Controller
{
    /**
     * ServerTransferController constructor.
     */
    public function __construct(
        private AlertsMessageBag $alert,
        private NodeRepository $nodeRepository,
        private TransferService $transferService,
        private DaemonConfigurationRepository $daemonConfigurationRepository
    ) {
    }

    /**
     * Starts a transfer of a server to a new node.
     *
     * @throws \Throwable
     */
    public function transfer(Request $request, Server $server): RedirectResponse
    {
        $validatedData = $request->validate([
            'node_id' => 'required|exists:nodes,id',
            'allocation_id' => 'required|bail|unique:servers|exists:allocations,id',
            'allocation_additional' => 'nullable',
        ]);

        $node_id = $validatedData['node_id'];
        $allocation_id = intval($validatedData['allocation_id']);
        $additional_allocations = array_map('intval', $validatedData['allocation_additional'] ?? []);

        // Check if the node is viable for the transfer.
        $node = $this->nodeRepository->getNodeWithResourceUsage($node_id);
        if ($node->isViable($server->memory, $server->disk)) {
            // Check if the selected daemon is online.
            $this->daemonConfigurationRepository->setNode($node)->getSystemInformation();

            $server->validateTransferState();

            // Create a new ServerTransfer entry.
            $transfer = new ServerTransfer();

            $transfer->server_id = $server->id;
            $transfer->old_node = $server->node_id;
            $transfer->new_node = $node_id;
            $transfer->old_allocation = $server->allocation_id;
            $transfer->new_allocation = $allocation_id;
            $transfer->old_additional_allocations = $server->allocations->where('id', '!=', $server->allocation_id)->pluck('id');
            $transfer->new_additional_allocations = $additional_allocations;

            $transfer->save();

            // Add the allocations to the server, so they cannot be automatically assigned while the transfer is in progress.
            $this->assignAllocationsToServer($server, $node_id, $allocation_id, $additional_allocations);

            // Request an archive from the server's current daemon. (this also checks if the daemon is online)
            $this->transferService->requestArchive($server);

            $this->alert->success(trans('admin/server.alerts.transfer_started'))->flash();
        } else {
            $this->alert->danger(trans('admin/server.alerts.transfer_not_viable'))->flash();
        }

        return redirect()->route('admin.servers.view.manage', $server->id);
    }

    /**
     * Assigns the specified allocations to the specified server.
     */
    private function assignAllocationsToServer(Server $server, int $node_id, int $allocation_id, array $additional_allocations)
    {
        $allocations = $additional_allocations;
        $allocations[] = $allocation_id;

        /** @var Node $node */
        $node = Node::query()->findOrFail($node_id);
        $unassigned = $node->allocations()
            ->whereNull('server_id')
            ->pluck('id')
            ->toArray();

        $updateIds = [];
        foreach ($allocations as $allocation) {
            if (!in_array($allocation, $unassigned)) {
                continue;
            }

            $updateIds[] = $allocation;
        }

        if (!empty($updateIds)) {
            Allocation::query()
                ->whereIn('id', $updateIds)
                ->update(['server_id' => $server->id]);
        }
    }
}
