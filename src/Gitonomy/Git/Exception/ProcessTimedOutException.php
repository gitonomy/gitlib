<?php

/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Exception;

/**
 * A git process ran longer than the timeout of CliDriver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final class ProcessTimedOutException extends DriverException
{
}
