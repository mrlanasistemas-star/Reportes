<?php

namespace App\Enums;

/**
 * Sistema de origen de un dato de empleado/asignación.
 *
 * lendus = sistema de cartera Lendus, noi = nómina NOI, manual = capturado
 * por una persona. Lo usan EmployeeBranchAssignment, EmployeeBranchAutoMatchService
 * y EmployeeBranchAssignmentController.
 */
enum SourceType: string
{
    case Lendus = 'lendus';
    case Noi = 'noi';
    case Manual = 'manual';

}
