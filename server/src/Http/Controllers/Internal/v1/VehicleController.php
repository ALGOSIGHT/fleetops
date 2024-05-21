<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\VehicleExport as VehicExports;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class VehicleController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'vehicle';

    /**
     * Get all status options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function statuses()
    {
        $statuses = DB::table('vehicles')
            ->select('status')
            ->where('company_uuid', session('company'))
            ->distinct()
            ->get()
            ->pluck('status')
            ->filter()
            ->values();

        return response()->json($statuses);
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = Vehicle::getAvatarOptions();

        return response()->json($options);
    }

    /**
     * Export the vehicles to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('vehicles-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new VehicleExport($selections), $fileName);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->input('files');
        $files          = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new VehicExports(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            if (count($data) === 1) {
                $imports = $imports->concat($data[0]);
            }
        }

        // prepare imports and fix phone
        $imports = $imports->map(
            function ($row) {

               // handle created at
                if (isset($row['created at'])) {
                    $row['created_at'] = $row['created at'];
                     unset($row['created at']);
                }

               // Handle id
                if (isset($row['id'])) {
                    $row['public_id'] = $row['id'];
                    unset($row['id']);
                }

                // set default values
                $row['status'] = 'active';
                $row['online'] = 0;

                return $row;
            })->values()->toArray();

        Vehicle::bulkInsert($imports);

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
